<?php declare(strict_types=1);

/*
 * Standalone acceptance script for Monolog\Formatter\SafeJsonFormatter.
 *
 * Runs against production dependencies only (psr/log) with native PHP
 * assertions, no PHPUnit required:
 *
 *     php -d zend.assertions=1 -d assert.exception=1 tests/safe-json-acceptance.php
 */

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\SafeJsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;

require __DIR__ . '/../vendor/autoload.php';

if (ini_get('zend.assertions') !== '1') {
    fwrite(STDERR, "WARNING: run with -d zend.assertions=1 -d assert.exception=1 so assertions are executed\n");
}

$checks = 0;
function ok(bool $cond, string $label): void
{
    global $checks;
    $checks++;
    assert($cond, $label);
    if (!$cond) {
        fwrite(STDERR, "FAILED: {$label}\n");
        exit(1);
    }
    echo "ok {$checks} - {$label}\n";
}

/** Malicious object: every callback increments a counter. */
class MaliciousSpy implements JsonSerializable, Stringable
{
    public static int $toStringCalls = 0;
    public static int $jsonSerializeCalls = 0;
    public static int $getterCalls = 0;

    public function __toString(): string
    {
        self::$toStringCalls++;
        return 'pwned';
    }

    public function jsonSerialize(): array
    {
        self::$jsonSerializeCalls++;
        return ['pwned' => true];
    }

    public function getPayload(): string
    {
        self::$getterCalls++;
        return 'pwned';
    }
}

function makeRecord(array $context = [], string $message = 'test', array $extra = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable('2026-10-04 12:34:56.789012', new DateTimeZone('UTC')),
        channel: 'acc-通道',
        level: Level::Warning,
        message: $message,
        context: $context,
        extra: $extra,
    );
}

// 1. Malicious Stringable/JsonSerializable object: zero callbacks, explicit marker
$formatter = new SafeJsonFormatter();
$record = makeRecord(['spy' => new MaliciousSpy(), 'plain' => '值']);
$json = $formatter->format($record);
$decoded = json_decode($json, true);
ok(MaliciousSpy::$toStringCalls === 0, 'Stringable::__toString callback count is 0');
ok(MaliciousSpy::$jsonSerializeCalls === 0, 'JsonSerializable::jsonSerialize callback count is 0');
ok(MaliciousSpy::$getterCalls === 0, 'object getter callback count is 0');
ok(is_array($decoded), 'context with malicious object still produces valid JSON');
ok($decoded['context']['spy'] === '[object:MaliciousSpy]', 'object rendered as explicit type marker');
ok($decoded['context']['plain'] === '值', 'Chinese scalar context preserved');

// 2. Real record values are kept
ok($decoded['level'] === 300 && $decoded['level_name'] === 'WARNING', 'level kept as real value');
ok($decoded['channel'] === 'acc-通道', 'channel kept as real value');
ok($decoded['datetime'] === '2026-10-04T12:34:56+00:00', 'datetime kept as real value');

// 3. Circular array terminates with a recursion truncation reason
$context = ['a' => 1];
$context['self'] = &$context;
$record = makeRecord($context);
$json = $formatter->format($record);
$decoded = json_decode($json, true);
ok(is_array($decoded), 'circular array context still produces valid JSON');
$self = $decoded['context']['self'];
$truncated = $self['self'] ?? $self;
ok(($truncated['__safe_truncated'] ?? false) === true, 'circular array yields truncation report');
ok($truncated['reason'] === SafeJsonFormatter::REASON_RECURSION, 'circular array reason is recursion');
ok(!array_key_exists("\0monolog.safe_json.recursion_guard\0", $record->context), 'no recursion guard leaked into record');

// 4. Depth limit and item limit are distinct reasons
$formatter = new SafeJsonFormatter(maxDepth: 2, maxItems: 3);
$deep = ['l1' => ['l2' => ['l3' => ['l4' => 'x']]]];
$decoded = json_decode($formatter->format(makeRecord($deep)), true);
$truncated = $decoded['context']['l1']['l2']['l3'];
ok($truncated['reason'] === SafeJsonFormatter::REASON_MAX_DEPTH, 'deep nesting reason is max_depth');
ok($truncated['max_depth'] === 2, 'max_depth report carries the configured limit');

$wide = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5];
$decoded = json_decode($formatter->format(makeRecord($wide)), true);
$truncated = $decoded['context']['...'];
ok($truncated['reason'] === SafeJsonFormatter::REASON_MAX_ITEMS, 'overflow reason is max_items');
ok($truncated['max_items'] === 3 && $truncated['total_items'] === 5, 'max_items report carries limit and total');
ok(SafeJsonFormatter::REASON_MAX_DEPTH !== SafeJsonFormatter::REASON_MAX_ITEMS, 'depth and item reasons are distinct');

// 5. Non-UTF-8 bytes: explicit byte escapes, record not dropped
$formatter = new SafeJsonFormatter();
$record = makeRecord(['bad' => "abc\xFF\xFE 中文 \xC3\x28", 'ok' => '正常'], "msg \x80 bad");
$json = $formatter->format($record);
$decoded = json_decode($json, true);
ok(is_array($decoded), 'record with bad bytes is not dropped and stays valid JSON');
ok($decoded['context']['bad'] === 'abc\xFF\xFE 中文 \xC3(', 'invalid bytes rendered as explicit \xNN escapes');
ok($decoded['context']['ok'] === '正常', 'valid Chinese untouched next to bad bytes');
ok($decoded['message'] === 'msg \x80 bad', 'bad bytes in message escaped too');

// 6. Resources get dedicated markers
$formatter = new SafeJsonFormatter();
$res = fopen('php://memory', 'rb');
$closed = fopen('php://memory', 'rb');
fclose($closed);
$decoded = json_decode($formatter->format(makeRecord(['res' => $res, 'closed' => $closed])), true);
ok($decoded['context']['res'] === '[resource:stream]', 'open resource marked as resource');
ok($decoded['context']['closed'] === '[resource:closed]', 'closed resource marked separately');
fclose($res);

// 7. Record invariance: formatting must not mutate the LogRecord
$record = makeRecord(['nested' => ['x' => [1, 2]], 'o' => new stdClass()], '不变', ['e' => 'v']);
$before = serialize([$record->message, $record->context, $record->extra, $record->level, $record->channel, $record->datetime->format('U.u')]);
$formatter->format($record);
$after = serialize([$record->message, $record->context, $record->extra, $record->level, $record->channel, $record->datetime->format('U.u')]);
ok($before === $after, 'LogRecord is unchanged after formatting');

// 8. Original JsonFormatter behavior is unchanged (still uses object callbacks)
MaliciousSpy::$toStringCalls = 0;
MaliciousSpy::$jsonSerializeCalls = 0;
(new JsonFormatter())->format(makeRecord(['spy' => new MaliciousSpy()]));
ok(MaliciousSpy::$toStringCalls + MaliciousSpy::$jsonSerializeCalls > 0, 'JsonFormatter still uses __toString/jsonSerialize (unchanged)');

// 9. Batch modes
$formatter = new SafeJsonFormatter();
$batch = json_decode($formatter->formatBatch([makeRecord(), makeRecord()]), true);
ok(is_array($batch) && count($batch) === 2, 'BATCH_MODE_JSON produces a JSON array');
$formatter = new SafeJsonFormatter(SafeJsonFormatter::BATCH_MODE_NEWLINES);
$lines = explode("\n", $formatter->formatBatch([makeRecord(), makeRecord()]));
ok(count($lines) === 2 && is_array(json_decode($lines[0], true)), 'BATCH_MODE_NEWLINES produces one JSON object per line');

echo "\nAll {$checks} acceptance checks passed.\n";
