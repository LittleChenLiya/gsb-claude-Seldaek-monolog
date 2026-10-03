# SafeJsonFormatter

`Monolog\Formatter\SafeJsonFormatter` encodes log records as JSON **without ever
executing userland object code**. Unlike `JsonFormatter`/`NormalizerFormatter`
it never calls `__toString()`, `jsonSerialize()`, getters or any other object
method while normalizing `context`/`extra`, so logging an untrusted or
"misbehaving" object cannot trigger callbacks, exceptions or side effects.

The record's own `datetime`, `level`, `level_name` and `channel` are always
emitted with their real values. `JsonFormatter` behavior is unchanged; this is
an explicit opt-in formatter.

## Normalization rules

| Input | Output |
| --- | --- |
| scalars / `null` | passed through (`INF`/`NAN` become `"INF"`/`"-INF"`/`"NaN"`) |
| arrays | traversed recursively (keys sanitized like string values) |
| objects | explicit type marker string, e.g. `[object:App\Evil]` — no method is ever called |
| resources | dedicated marker, e.g. `[resource:stream]` or `[resource:closed]` |
| non-UTF-8 strings | valid sequences (e.g. Chinese) kept, every invalid byte rendered as an explicit `\xNN` escape; the record is never dropped |

## Truncation reports

Circular array references, the depth limit and the per-array item limit each
produce a deterministic truncation report with a **distinct** machine-readable
`reason`:

```json
{"__safe_truncated": true, "reason": "recursion", "detail": "circular array reference detected"}
{"__safe_truncated": true, "reason": "max_depth", "max_depth": 9}
{"__safe_truncated": true, "reason": "max_items", "max_items": 1000, "total_items": 1500}
```

The reasons are exposed as `SafeJsonFormatter::REASON_RECURSION`,
`SafeJsonFormatter::REASON_MAX_DEPTH` and `SafeJsonFormatter::REASON_MAX_ITEMS`.
Item truncations are appended under a `...` key of the truncated array.

## Configuration

```php
use Monolog\Formatter\SafeJsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$formatter = new SafeJsonFormatter(
    batchMode: SafeJsonFormatter::BATCH_MODE_JSON, // or BATCH_MODE_NEWLINES
    appendNewline: true,        // append "\n" after each formatted record
    maxDepth: 9,                // max array nesting before a max_depth truncation
    maxItems: 1000,             // max items per array before a max_items truncation
    dateFormat: 'Y-m-d\TH:i:sP' // format for the record datetime
);

$handler = new StreamHandler('php://stderr');
$handler->setFormatter($formatter);
$log = new Logger('app', [$handler]);
```

## Example output

```php
$ctx = ['user' => '张三', 'file' => fopen('php://memory', 'rb'), 'obj' => new \ArrayObject([1])];
$ctx['loop'] = &$ctx; // circular reference
$log->warning("支付失败 \xFF", $ctx);
```

```json
{"message":"支付失败 \\xFF","context":{"user":"张三","file":"[resource:stream]","obj":"[object:ArrayObject]","loop":{"user":"张三","file":"[resource:stream]","obj":"[object:ArrayObject]","loop":{"__safe_truncated":true,"reason":"recursion","detail":"circular array reference detected"}}},"level":300,"level_name":"WARNING","channel":"app","datetime":"2026-10-04T12:34:56+08:00","extra":[]}
```

## Acceptance / tests

A standalone acceptance script using only production dependencies (psr/log)
and native PHP assertions is provided:

```bash
php -d zend.assertions=1 -d assert.exception=1 tests/safe-json-acceptance.php
```

The PHPUnit test suite lives in `tests/Monolog/Formatter/SafeJsonFormatterTest.php`.
