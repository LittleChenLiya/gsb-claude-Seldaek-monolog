# ContextBudgetProcessor

`Monolog\Processor\ContextBudgetProcessor` is an optional processor that keeps a
single log record's context from growing past a fixed size, for example before
sending records over UDP (GELF/Syslog), to a document store with size limits,
or to any backend that charges per payload.

```php
use Monolog\Processor\ContextBudgetProcessor;

// Keep the estimated context at or below 2048 UTF-8 bytes.
$logger->pushProcessor(new ContextBudgetProcessor(2048));
```

## Guarantees

- **No repeated JSON encoding.** Entry sizes are accumulated with a
  deterministic byte estimator; values are never JSON-encoded, so large
  strings are counted once and encoding work is not done at all.
- **No user code is executed on objects.** Unknown objects are treated as
  opaque leaves; neither `__toString()` nor `JsonSerializable::jsonSerialize()`
  (nor any other object method) is called.
- **Original key order is preserved.** Entries are walked in their original
  order and fitting entries keep that order.
- **Nested values are atomic.** A nested entry is either kept completely, with
  every descendant, or rejected entirely — a value is never truncated
  half-way.
- **The incoming `LogRecord` is not modified.** When nothing is dropped the
  same record instance is returned; otherwise a new record is returned via
  `LogRecord::with()`.
- **No shared accumulator state.** Every invocation creates fresh local
  counters; processing record B can never be influenced by record A.

## Budget accounting rules

The budget is counted in **estimated UTF-8 bytes** of the serialized data
structure (JSON-like), not in characters and not in PHP variable size:

| Element                        | Charged cost                                                                 |
|--------------------------------|------------------------------------------------------------------------------|
| string value / string key      | raw UTF-8 byte length (e.g. `你好` = 6 bytes for 2 characters)               |
| `null`                         | 4 bytes (`null`)                                                             |
| boolean                        | 4 bytes (`true`) or 5 bytes (`false`)                                        |
| integer                        | byte length of its canonical string form (e.g. `-12` = 3)                    |
| float                          | byte length of its JSON form (e.g. `1.5` = 3); `NAN`/`INF` count as `null`   |
| `BackedEnum`                   | byte length of its backing scalar value, no user code called                 |
| plain `UnitEnum`               | byte length of its case name                                                 |
| unknown object                 | `8 + class-name-bytes + 1` for the opaque `[object Class\Name]` leaf         |
| resource                       | `10 + resource-type-bytes + 1` for the opaque `[resource type]` leaf         |
| key/value pair (string key)    | key bytes + 1 byte for the `:` between key and value                         |
| integer key (list element)     | no key and no colon are charged (JSON lists do not serialize keys)           |
| array/object container         | fixed 2 bytes for the surrounding brackets/braces                            |
| element after the first        | 1 byte per element for the `,` separator                                     |
| nesting deeper than 128 levels | the array is treated as a 7-byte opaque `[array]` leaf, still kept as a whole |

Entries are charged one by one against the budget. An entry fits when
`used + entryCost <= budget`; otherwise it is dropped. Entries after a dropped
one are still evaluated, so a later small entry can still be kept.

## Truncation summary

When one or more entries are dropped, the processor adds a summary to the
record's `extra` data (key configurable via the second constructor argument,
default `context_budget`):

```php
[
    'budget'  => 2048, // configured budget
    'used'    => 1312, // bytes consumed by the entries kept
    'dropped' => [
        ['path' => 'payload', 'bytes' => 4120], // entry key + estimated bytes
    ],
]
```

`path` is the context key of the rejected top-level entry; `bytes` is the full
estimated cost of the entry (key, colon and value, including nested structure)
that did not fit. Existing `extra` data of the record is preserved.

## Running the demo

A dependency-free native PHP example ships in
[`examples/context_budget_demo.php`](../examples/context_budget_demo.php). It
needs only the production dependencies (`psr/log`):

```sh
php composer.phar install --no-dev
php examples/context_budget_demo.php
```
