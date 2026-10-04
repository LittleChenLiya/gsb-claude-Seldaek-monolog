# ContextBudgetProcessor — budget rules

`Monolog\Processor\ContextBudgetProcessor` keeps the `context` of a log record
within a fixed byte budget so that a single log record cannot be blown up by an
oversized context field. It is an opt-in processor: add it to your logger (or to
a single handler) like any other processor.

```php
$logger->pushProcessor(new ContextBudgetProcessor(maxBytes: 4096));
```

## Accounting rules

The budget is counted in **UTF-8 bytes**, accumulated entry by entry. The
processor never `json_encode()`s a value repeatedly to measure it; it walks the
value once and sums the following fixed costs.

| Item | Cost |
| --- | --- |
| Container (top-level context, nested array) | 2 bytes (`{}` / `[]`) |
| String key | 2 quote bytes + UTF-8 key bytes + 1 colon byte |
| String value | 2 quote bytes + UTF-8 byte length (`strlen()`, which counts bytes, not characters — `"中文"` costs 6, not 2) |
| Integer value | byte length of the `(string)` cast (no user code runs) |
| Float value | byte length of the `(string)` cast (no user code runs) |
| `null` / `true` / `false` | 4 / 4 / 5 bytes |
| `BackedEnum` | cost of its backing scalar |
| `UnitEnum` | 2 quote bytes + byte length of its `name` |
| Unknown object | 2 quote bytes + byte length of `"[object FQCN]"` |
| Resource | 2 quote bytes + byte length of `"[resource:type]"` |
| Entry separator | 1 comma byte per entry (the last entry is counted as well — a deliberate 1-byte over-estimate that keeps the accounting branch-free) |

The total cost of an entry is its key cost plus its value cost plus the comma.
The top-level container costs 2 bytes.

## Keep / drop semantics

- Entries are evaluated in **original key order**. An entry whose total cost
  fits the remaining budget is kept; an entry that does not fit is dropped.
- **Nested entries are kept or rejected as a whole.** An array value is costed
  in full and either kept unchanged or dropped unchanged — no half values are
  ever left behind.
- Dropped entries are recorded in a summary written under the `_budget` key
  (configurable via the second constructor argument):

```php
'_budget' => [
    'budget_bytes'  => 4096,  // the configured budget
    'used_bytes'    => 123,   // estimated bytes occupied by the kept entries
    'dropped_bytes'  => [     // dropped path => estimated byte cost
        'big_field' => 512,
    ],
]
```

The summary itself is metadata and is not counted against the budget.

## Objects never execute user code

The processor never calls `__toString()` or `jsonSerialize()` on context
values. Unknown objects are costed as the placeholder string
`"[object FQCN]"` (the declaration site PHP appends to anonymous class names
is stripped), so a context value with a side-effecting `__toString()` is
costed and kept without ever running it.

## Immutability and per-call state

- The original `LogRecord` is never modified; the processor returns a new
  record via `$record->with(context: ...)`.
- All accounting state is local to each `__invoke()` call. Consecutive calls
  on the same processor instance never share accumulated state, so a field
  dropped in one record has no effect on the next record.

## Boundary behavior

Budget comparison is `cost <= remaining`: an entry whose cost exactly equals
the remaining budget is kept; one byte more is dropped.
