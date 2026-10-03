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

use JsonSerializable;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Test\MonologTestCase;
use Stringable;

class SafeJsonFormatterTest extends MonologTestCase
{
    public function testMaliciousStringableAndJsonSerializableObjectExecutesNoCallback()
    {
        $formatter = new SafeJsonFormatter();
        $object = new class implements JsonSerializable, Stringable {
            public int $calls = 0;
            public string $public = 'kept';
            public function __toString(): string
            {
                $this->calls++;
                return 'malicious';
            }
            public function jsonSerialize(): mixed
            {
                $this->calls++;
                return ['malicious'];
            }
            public function __get(string $name): mixed
            {
                $this->calls++;
                return null;
            }
        };

        $formatted = $formatter->format($this->getRecord(context: ['object' => $object]));

        $this->assertSame(0, $object->calls, 'No __toString/jsonSerialize/__get callback may be invoked');
        $decoded = json_decode($formatted, true);
        $this->assertSame('[object(JsonSerializable@anonymous)]', $decoded['context']['object']);
    }

    public function testMaliciousThrowableExecutesNoGetter()
    {
        $formatter = new SafeJsonFormatter();
        $exception = new class ('boom') extends \RuntimeException {
            public int $toStringCalls = 0;
            public function __toString(): string
            {
                $this->toStringCalls++;
                return parent::__toString();
            }
        };

        $formatted = $formatter->format($this->getRecord(context: ['exception' => $exception]));

        $this->assertSame(0, $exception->toStringCalls);
        $decoded = json_decode($formatted, true);
        $this->assertSame('[object(RuntimeException@anonymous)]', $decoded['context']['exception']);
    }

    public function testNormalContextIsValidJson()
    {
        $formatter = new SafeJsonFormatter();
        $formatted = $formatter->format($this->getRecord(context: [
            'string' => 'value',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
            'list' => [1, 2, 3],
            'assoc' => ['a' => 'b'],
        ]));

        $decoded = json_decode($formatted, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('value', $decoded['context']['string']);
        $this->assertSame(42, $decoded['context']['int']);
        $this->assertSame(3.14, $decoded['context']['float']);
        $this->assertTrue($decoded['context']['bool']);
        $this->assertNull($decoded['context']['null']);
        $this->assertSame([1, 2, 3], $decoded['context']['list']);
        $this->assertSame(['a' => 'b'], $decoded['context']['assoc']);
    }

    public function testChineseCharactersArePreserved()
    {
        $formatter = new SafeJsonFormatter();
        $formatted = $formatter->format($this->getRecord(context: [
            'greeting' => '你好世界',
            'nested' => ['中文' => '测试'],
        ]));

        $decoded = json_decode($formatted, true);
        $this->assertSame('你好世界', $decoded['context']['greeting']);
        $this->assertSame('测试', $decoded['context']['nested']['中文']);
        $this->assertStringContainsString('你好世界', $formatted);
    }

    public function testInvalidUtf8BytesAreRenderedExplicitlyAndRecordStillEncodes()
    {
        $formatter = new SafeJsonFormatter();
        $formatted = $formatter->format($this->getRecord(
            message: "bad bytes: \xFF and \x80",
            context: ['bad' => "a\xFFb\x80c"],
        ));

        $decoded = json_decode($formatted, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'The whole record must still encode');
        $this->assertSame('a\\xFFb\\x80c', $decoded['context']['bad']);
        $this->assertStringContainsString('\\xFF', $decoded['message']);
    }

    public function testCircularArrayHasTruncationReasonAndTerminates()
    {
        $formatter = new SafeJsonFormatter();
        $circular = [];
        $circular['self'] = &$circular;

        $formatted = $formatter->format($this->getRecord(context: ['circular' => $circular]));

        $decoded = json_decode($formatted, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame(
            'Circular reference detected, aborting normalization',
            $decoded['context']['circular']['self']['self']
        );
    }

    public function testDepthTruncationReason()
    {
        $formatter = new SafeJsonFormatter();
        $deep = [];
        $pointer = &$deep;
        for ($i = 0; $i < 20; $i++) {
            $pointer['child'] = [];
            $pointer = &$pointer['child'];
        }

        $formatted = $formatter->format($this->getRecord(context: ['deep' => $deep]));

        $this->assertStringContainsString('Over 9 levels deep, aborting normalization', $formatted);
        $decoded = json_decode($formatted, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function testItemTruncationReason()
    {
        $formatter = new SafeJsonFormatter();
        $formatted = $formatter->format($this->getRecord(context: ['many' => range(0, 1500)]));

        $this->assertStringContainsString('Over 1000 items (1501 total), aborting normalization', $formatted);
        $decoded = json_decode($formatted, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertCount(1001, $decoded['context']['many']);
    }

    public function testDepthAndItemReasonsAreDistinct()
    {
        $this->assertNotSame(
            SafeJsonFormatter::REASON_DEPTH,
            SafeJsonFormatter::REASON_ITEMS,
        );
        $this->assertNotSame(
            SafeJsonFormatter::REASON_DEPTH,
            SafeJsonFormatter::REASON_CIRCULAR,
        );
        $this->assertNotSame(
            SafeJsonFormatter::REASON_ITEMS,
            SafeJsonFormatter::REASON_CIRCULAR,
        );
    }

    public function testRecordIsNotMutated()
    {
        $formatter = new SafeJsonFormatter();
        $object = new \stdClass();
        $object->name = 'untouched';
        $circular = [];
        $circular['self'] = &$circular;
        $record = $this->getRecord(context: ['object' => $object, 'circular' => $circular]);

        $formatter->format($record);

        $this->assertSame($object, $record->context['object']);
        $this->assertSame('untouched', $record->context['object']->name);
        $this->assertSame($circular, $record->context['circular']);
        $this->assertSame($circular['self'], $record->context['circular']['self']);
    }

    public function testDatetimeLevelAndChannelAreRealValues()
    {
        $formatter = new SafeJsonFormatter();
        $datetime = new \DateTimeImmutable('2024-01-02 03:04:05');
        $record = $this->getRecord(
            Level::Error,
            'message',
            channel: 'app',
            datetime: $datetime,
        );

        $decoded = json_decode($formatter->format($record), true);

        $this->assertSame(400, $decoded['level']);
        $this->assertSame('ERROR', $decoded['level_name']);
        $this->assertSame('app', $decoded['channel']);
        $this->assertSame('2024-01-02T03:04:05+00:00', $decoded['datetime']);
    }

    public function testResourceIsMarkedSeparately()
    {
        $formatter = new SafeJsonFormatter();
        $formatted = $formatter->format($this->getRecord(context: ['resource' => opendir(__DIR__)]));

        $decoded = json_decode($formatted, true);
        $this->assertSame('[resource(stream)]', $decoded['context']['resource']);
    }

    public function testNamedObjectIsMarkedWithItsClass()
    {
        $formatter = new SafeJsonFormatter();
        $formatted = $formatter->format($this->getRecord(context: ['object' => new \ArrayObject(['a' => 'b'])]));

        $decoded = json_decode($formatted, true);
        $this->assertSame('[object(ArrayObject)]', $decoded['context']['object']);
    }

    public function testFormatBatchReturnsValidJsonArray()
    {
        $formatter = new SafeJsonFormatter();
        $records = [
            $this->getRecord(Level::Warning, 'one', context: ['a' => 1]),
            $this->getRecord(Level::Debug, 'two', context: ['b' => 2]),
        ];

        $formatted = $formatter->formatBatch($records);

        $decoded = json_decode($formatted, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertCount(2, $decoded);
        $this->assertSame('one', $decoded[0]['message']);
        $this->assertSame('two', $decoded[1]['message']);
    }

    public function testOriginalJsonFormatterStillCallsCallbacks()
    {
        $formatter = new JsonFormatter();
        $object = new class implements Stringable {
            public int $calls = 0;
            public function __toString(): string
            {
                $this->calls++;
                return 'stringified';
            }
        };

        $formatted = $formatter->format($this->getRecord(context: ['object' => $object]));

        $this->assertSame(1, $object->calls);
        $decoded = json_decode($formatted, true);
        $this->assertSame('stringified', $decoded['context']['object']);
    }
}
