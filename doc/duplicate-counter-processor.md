# DuplicateCounterProcessor

The `DuplicateCounterProcessor` counts duplicate log records while preserving
the first occurrence. Each record is assigned a sequence number that increments
every time the same identity is seen.

## Identity Composition

The identity of a record is composed of:

- **channel** — the logger channel name
- **level** — the log level (DEBUG, INFO, WARNING, etc.)
- **message** — the log message
- **context fields** — caller-selected scalar fields from the record context

### Type Distinction

The processor distinguishes between different types of the same value:

| Value | Identity |
|-------|----------|
| integer `1` | distinct from string `"1"` |
| string `"1"` | distinct from integer `1` |
| `null` | distinct from missing field |
| missing field | distinct from `null` |
| boolean `true` | distinct from float `1.0` and integer `1` |

### Rejected Values

Only scalar values (`int`, `float`, `string`, `bool`) and `null` are accepted
as context field values. Arrays and objects are rejected with an
`InvalidArgumentException`. The processor does **not** call `serialize()` or
any other method to guess the value of unknown objects.

## Cache Behavior

The cache has a **fixed capacity** set in the constructor. When the cache is
full and a new identity is encountered, the **least recently used** (LRU) entry
is evicted to make room.

Accessing an existing identity moves it to the most recently used position, so
frequently accessed identities are not evicted.

## Reset

Calling `reset()` clears the entire cache. The next record after reset will
have sequence number `1`, regardless of any previous counts.

## Immutability

The original log record is **never modified**. The processor returns a new
`LogRecord` with the `duplicate_count` field added to the `extra` array. The
logger configuration is also not modified.

## Usage

```php
use Monolog\Logger;
use Monolog\Processor\DuplicateCounterProcessor;

$logger = new Logger('app');
$logger->pushProcessor(new DuplicateCounterProcessor(
    capacity: 100,
    contextFields: ['user_id', 'request_id']
));

// First occurrence: duplicate_count = 1
$logger->info('User logged in', ['user_id' => 123]);

// Second occurrence: duplicate_count = 2
$logger->info('User logged in', ['user_id' => 123]);

// Different context value type: duplicate_count = 1 (new identity)
$logger->info('User logged in', ['user_id' => '123']);
```

## Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `capacity` | `int<1, max>` | Maximum number of identities to track |
| `contextFields` | `string[]` | Context field names to include in identity |

## Sequence Number Location

The sequence number is stored in `$record->extra['duplicate_count']`.
