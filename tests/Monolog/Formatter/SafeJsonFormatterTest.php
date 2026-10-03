<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Formatter;

use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Test\MonologTestCase;

class SafeJsonFormatterTest extends MonologTestCase
{
    public function testConstruct()
    {
        $formatter = new SafeJsonFormatter();
        $this->assertSame(SafeJsonFormatter::BATCH_MODE_JSON, $formatter->getBatchMode());
        $this->assertTrue($formatter->isAppendingNewlines());
        $this->assertSame(9, $formatter->getMaxDepth());
        $this->assertSame(1000, $formatter->getMaxItems());

        $formatter = new SafeJsonFormatter(SafeJsonFormatter::BATCH_MODE_NEWLINES, false, 3, 5);
        $this->assertSame(SafeJsonFormatter::BATCH_MODE_NEWLINES, $formatter->getBatchMode());
        $this->assertFalse($formatter->isAppendingNewlines());
        $this->assertSame(3, $formatter->getMaxDepth());
        $this->assertSame(5, $formatter->getMaxItems());
    }

    public function testFormatKeepsRealRecordValuesAndValidJson()
    {
        $formatter = new SafeJsonFormatter();
        $datetime = new \DateTimeImmutable('2024-05-06 07:08:09.123456', new \DateTimeZone('Asia/Shanghai'));
        $record = $this->getRecord(Level::Error, '你好 message', ['key' => 'value', 'n' => 1.5], 'app-通道', $datetime, ['x' => true]);

        $json = $formatter->format($record);
        $this->assertStringEndsWith("\n", $json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'output must be valid JSON');
        $this->assertSame('你好 message', $decoded['message']);
        $this->assertSame(400, $decoded['level']);
        $this->assertSame('ERROR', $decoded['level_name']);
        $this->assertSame('app-通道', $decoded['channel']);
        $this->assertSame($datetime->format(SafeJsonFormatter::SIMPLE_DATE), $decoded['datetime']);
        $this->assertSame(['key' => 'value', 'n' => 1.5], $decoded['context']);
        $this->assertSame(['x' => true], $decoded['extra']);
    }

    public function testFormatNeverInvokesObjectBehavior()
    {
        SafeJsonFormatterTestSpy::$toStringCalls = 0;
        SafeJsonFormatterTestSpy::$jsonSerializeCalls = 0;
        SafeJsonFormatterTestSpy::$getterCalls = 0;

        $formatter = new SafeJsonFormatter();
        $record = $this->getRecord(context: ['spy' => new SafeJsonFormatterTestSpy()]);
        $decoded = json_decode($formatter->format($record), true);

        $this->assertSame(0, SafeJsonFormatterTestSpy::$toStringCalls, '__toString must not be called');
        $this->assertSame(0, SafeJsonFormatterTestSpy::$jsonSerializeCalls, 'jsonSerialize must not be called');
        $this->assertSame(0, SafeJsonFormatterTestSpy::$getterCalls, 'getters must not be called');
        $this->assertSame('[object:' . SafeJsonFormatterTestSpy::class . ']', $decoded['context']['spy']);
    }

    public function testResourcesGetDedicatedMarkers()
    {
        $formatter = new SafeJsonFormatter();
        $resource = fopen('php://memory', 'rb');
        $closed = fopen('php://memory', 'rb');
        fclose($closed);

        $record = $this->getRecord(context: ['res' => $resource, 'closed' => $closed]);
        $decoded = json_decode($formatter->format($record), true);

        $this->assertSame('[resource:stream]', $decoded['context']['res']);
        $this->assertSame('[resource:closed]', $decoded['context']['closed']);
        fclose($resource);
    }

    public function testCircularArrayTerminatesWithRecursionReason()
    {
        $formatter = new SafeJsonFormatter();
        $context = ['a' => 1];
        $context['self'] = &$context;

        $record = $this->getRecord(context: $context);
        $json = $formatter->format($record);
        $decoded = json_decode($json, true);

        $this->assertSame(1, $decoded['context']['a']);
        $self = $decoded['context']['self'];
        // the cycle may be detected one level down depending on reference layout
        $truncated = $self['self'] ?? $self;
        $this->assertTrue($truncated['__safe_truncated']);
        $this->assertSame(SafeJsonFormatter::REASON_RECURSION, $truncated['reason']);
        // the record itself must not be polluted by the recursion guard
        $this->assertArrayNotHasKey("\0monolog.safe_json.recursion_guard\0", $record->context);
    }

    public function testDepthAndItemLimitsHaveDistinctReasons()
    {
        $formatter = new SafeJsonFormatter(maxDepth: 2, maxItems: 3);

        $deep = ['l1' => ['l2' => ['l3' => ['l4' => 'too deep']]]];
        $decoded = json_decode($formatter->format($this->getRecord(context: $deep)), true);
        $truncated = $decoded['context']['l1']['l2']['l3'];
        $this->assertTrue($truncated['__safe_truncated']);
        $this->assertSame(SafeJsonFormatter::REASON_MAX_DEPTH, $truncated['reason']);
        $this->assertSame(2, $truncated['max_depth']);

        $wide = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5];
        $decoded = json_decode($formatter->format($this->getRecord(context: $wide)), true);
        $this->assertSame(1, $decoded['context']['a']);
        $this->assertSame(3, $decoded['context']['c']);
        $this->assertArrayNotHasKey('d', $decoded['context']);
        $truncated = $decoded['context']['...'];
        $this->assertTrue($truncated['__safe_truncated']);
        $this->assertSame(SafeJsonFormatter::REASON_MAX_ITEMS, $truncated['reason']);
        $this->assertSame(3, $truncated['max_items']);
        $this->assertSame(5, $truncated['total_items']);

        $this->assertNotSame(SafeJsonFormatter::REASON_MAX_DEPTH, SafeJsonFormatter::REASON_MAX_ITEMS);
    }

    public function testNonUtf8BytesAreExplicitAndRecordIsNotDropped()
    {
        $formatter = new SafeJsonFormatter();
        $bad = "valid 中文 \xC3\x28 invalid \xFF\xFE tail";
        $record = $this->getRecord(message: $bad, context: ['bad' => "\x80 lone", 'ok' => '正常']);

        $json = $formatter->format($record);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'record with bad bytes must still be valid JSON');
        $this->assertSame('valid 中文 \xC3( invalid \xFF\xFE tail', $decoded['message']);
        $this->assertSame('\x80 lone', $decoded['context']['bad']);
        $this->assertSame('正常', $decoded['context']['ok']);
    }

    public function testFormatDoesNotMutateRecord()
    {
        $formatter = new SafeJsonFormatter();
        $context = ['nested' => ['list' => [1, 2, 3]], 'obj' => new \stdClass()];
        $record = $this->getRecord(context: $context, extra: ['e' => 'v']);
        $before = serialize([$record->message, $record->context, $record->extra, $record->level, $record->channel, $record->datetime->format('U.u')]);

        $formatter->format($record);

        $after = serialize([$record->message, $record->context, $record->extra, $record->level, $record->channel, $record->datetime->format('U.u')]);
        $this->assertSame($before, $after, 'format() must not mutate the LogRecord');
    }

    public function testFormatBatch()
    {
        $formatter = new SafeJsonFormatter();
        $records = $this->getMultipleRecords();
        $decoded = json_decode($formatter->formatBatch($records), true);
        $this->assertIsArray($decoded);
        $this->assertCount(5, $decoded);
        $this->assertSame('debug message 1', $decoded[0]['message']);

        $formatter = new SafeJsonFormatter(SafeJsonFormatter::BATCH_MODE_NEWLINES);
        $lines = explode("\n", $formatter->formatBatch($records));
        $this->assertCount(5, $lines);
        foreach ($lines as $line) {
            $this->assertIsArray(json_decode($line, true));
        }
    }

    public function testSpecialFloatsAndScalars()
    {
        $formatter = new SafeJsonFormatter();
        $record = $this->getRecord(context: ['inf' => INF, 'ninf' => -INF, 'nan' => NAN, 'bool' => false, 'null' => null, 'int' => 7]);
        $decoded = json_decode($formatter->format($record), true);
        $this->assertSame('INF', $decoded['context']['inf']);
        $this->assertSame('-INF', $decoded['context']['ninf']);
        $this->assertSame('NaN', $decoded['context']['nan']);
        $this->assertFalse($decoded['context']['bool']);
        $this->assertNull($decoded['context']['null']);
        $this->assertSame(7, $decoded['context']['int']);
    }

    public function testJsonFormatterBehaviorIsUnchanged()
    {
        // the original JsonFormatter must still use __toString/jsonSerialize
        SafeJsonFormatterTestSpy::$toStringCalls = 0;
        SafeJsonFormatterTestSpy::$jsonSerializeCalls = 0;

        $formatter = new JsonFormatter();
        $formatter->format($this->getRecord(context: ['spy' => new SafeJsonFormatterTestSpy()]));

        $this->assertGreaterThan(0, SafeJsonFormatterTestSpy::$jsonSerializeCalls + SafeJsonFormatterTestSpy::$toStringCalls);
    }
}

class SafeJsonFormatterTestSpy implements \JsonSerializable, \Stringable
{
    public static int $toStringCalls = 0;
    public static int $jsonSerializeCalls = 0;
    public static int $getterCalls = 0;

    public function __toString(): string
    {
        self::$toStringCalls++;

        return 'spy-string';
    }

    public function jsonSerialize(): array
    {
        self::$jsonSerializeCalls++;

        return ['spy' => 'json'];
    }

    public function getSecret(): string
    {
        self::$getterCalls++;

        return 'spy-secret';
    }
}
