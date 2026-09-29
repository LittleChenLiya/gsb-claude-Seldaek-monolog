<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Handler;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\Level;
use Monolog\LogRecord;

class ByteBufferHandlerTest extends \Monolog\Test\MonologTestCase
{
    private function getRecordByteSize(LogRecord $record): int
    {
        $serialized = json_encode(
            (new NormalizerFormatter())->format($record),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return \strlen(false === $serialized ? '' : $serialized);
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::handle
     * @covers Monolog\Handler\ByteBufferHandler::getBufferByteSize
     */
    public function testHandleBuffersAndCountsUtf8Bytes()
    {
        $test = new TestHandler();
        $handler = new ByteBufferHandler($test);

        $record = $this->getRecord(Level::Info, '日志消息', [
            '用户' => '管理员',
            'payload' => str_repeat('数据', 500),
            'nested' => ['说明' => '包含中文的大上下文', 'count' => 42],
        ]);
        $expectedSize = $this->getRecordByteSize($record);

        // the serialized form holds multi-byte UTF-8 chars, so its byte
        // length must be larger than its character count
        $serialized = json_encode((new NormalizerFormatter())->format($record), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertGreaterThan(mb_strlen($serialized), $expectedSize);

        $handler->handle($record);
        $this->assertSame($expectedSize, $handler->getBufferByteSize());

        $second = $this->getRecord(Level::Info, 'second 记录');
        $handler->handle($second);
        $this->assertSame($expectedSize + $this->getRecordByteSize($second), $handler->getBufferByteSize());

        $this->assertFalse($test->hasInfoRecords());
        $handler->close();
        $this->assertCount(2, $test->getRecords());
        $this->assertSame(0, $handler->getBufferByteSize());
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::handle
     */
    public function testByteLimitMatchesExactSerializedSize()
    {
        $test = new TestHandler();
        $record = $this->getRecord(Level::Info, '中文消息', ['上下文' => '数据']);
        $size = $this->getRecordByteSize($record);

        // a limit matching the exact byte size accepts the record
        $handler = new ByteBufferHandler($test, $size);
        $handler->handle($record);
        $this->assertSame($size, $handler->getBufferByteSize());

        // one byte less rejects it
        $handler = new ByteBufferHandler($test, $size - 1);
        $handler->handle($record);
        $this->assertSame(0, $handler->getBufferByteSize());
        $this->assertCount(0, $test->getRecords());
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::handle
     */
    public function testByteLimitRejectsNewRecordsByDefault()
    {
        $test = new TestHandler();
        $record = $this->getRecord(Level::Info, '一条记录');
        $size = $this->getRecordByteSize($record);

        $handler = new ByteBufferHandler($test, $size);
        $handler->handle($record);

        // buffer is exactly full, a new record must be rejected without flushing
        $rejected = $this->getRecord(Level::Info, 'rejected');
        $this->assertFalse($handler->handle($rejected));
        $this->assertSame($size, $handler->getBufferByteSize());
        $this->assertCount(0, $test->getRecords());

        $handler->close();
        $records = $test->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('一条记录', $records[0]->message);
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::handle
     * @covers Monolog\Handler\ByteBufferHandler::flush
     */
    public function testByteLimitFlushOnOverflow()
    {
        $test = new TestHandler();
        $first = $this->getRecord(Level::Info, '记录', ['n' => 1]);
        $second = $this->getRecord(Level::Info, '记录', ['n' => 2]);
        $third = $this->getRecord(Level::Info, '记录', ['n' => 3]);

        // the limit fits exactly the first two records
        $limit = $this->getRecordByteSize($first) + $this->getRecordByteSize($second);
        $handler = new ByteBufferHandler($test, $limit, Level::Debug, true, true);

        $handler->handle($first);
        $handler->handle($second);
        $this->assertCount(0, $test->getRecords());

        // the third record does not fit anymore, the buffer must be flushed first
        $handler->handle($third);
        $this->assertCount(2, $test->getRecords());
        $this->assertSame($this->getRecordByteSize($third), $handler->getBufferByteSize());

        $handler->close();
        $records = $test->getRecords();
        $this->assertCount(3, $records);
        $this->assertSame([1, 2, 3], array_map(fn (LogRecord $rec) => $rec->context['n'], $records));
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::handle
     */
    public function testOversizedRecordIsDeliveredDirectlyWhenFlushingOnOverflow()
    {
        $test = new TestHandler();
        $small = $this->getRecord(Level::Info, 'small');
        $handler = new ByteBufferHandler($test, $this->getRecordByteSize($small), Level::Debug, true, true);

        $handler->handle($small);
        $this->assertCount(0, $test->getRecords());

        // a single record bigger than the whole capacity is sent as its own
        // batch right away instead of sitting in the buffer above the limit
        $handler->handle($this->getRecord(Level::Info, 'way too large to ever fit in the buffer'));
        $this->assertCount(2, $test->getRecords());
        $this->assertSame(0, $handler->getBufferByteSize());

        $handler->close();
        $records = $test->getRecords();
        $this->assertCount(2, $records);
        $this->assertSame('small', $records[0]->message);
        $this->assertSame('way too large to ever fit in the buffer', $records[1]->message);
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::handle
     */
    public function testOversizedRecordIsRejectedByDefault()
    {
        $test = new TestHandler();
        $record = $this->getRecord(Level::Info, 'some record');
        $handler = new ByteBufferHandler($test, $this->getRecordByteSize($record) - 1);

        $handler->handle($record);
        $this->assertSame(0, $handler->getBufferByteSize());

        $handler->close();
        $this->assertCount(0, $test->getRecords());
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::flush
     */
    public function testFailingHandlerKeepsBatchForRetryInOrder()
    {
        $test = new TestHandler();
        $failOnce = new class($test) extends AbstractHandler {
            /** @var LogRecord[][] */
            public array $batches = [];
            private int $calls = 0;

            public function __construct(private TestHandler $inner)
            {
                parent::__construct();
            }

            public function handle(LogRecord $record): bool
            {
                return false;
            }

            public function handleBatch(array $records): void
            {
                $this->calls++;
                if (1 === $this->calls) {
                    throw new \RuntimeException('simulated failure');
                }
                $this->batches[] = $records;
                $this->inner->handleBatch($records);
            }
        };

        $handler = new ByteBufferHandler($failOnce);
        $handler->handle($this->getRecord(Level::Info, 'first'));
        $handler->handle($this->getRecord(Level::Warning, 'second'));
        $bufferedSize = $handler->getBufferByteSize();

        try {
            $handler->flush();
            $this->fail('Expected the simulated failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }

        // the failed batch must be retained for a retry
        $this->assertSame($bufferedSize, $handler->getBufferByteSize());
        $this->assertCount(0, $test->getRecords());

        $handler->flush();
        $this->assertSame(0, $handler->getBufferByteSize());
        $this->assertCount(1, $failOnce->batches);
        $this->assertSame(['first', 'second'], array_map(fn (LogRecord $rec) => $rec->message, $failOnce->batches[0]));
        $this->assertSame(['first', 'second'], array_map(fn (LogRecord $rec) => $rec->message, $test->getRecords()));
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::flush
     */
    public function testRecursiveWriteDuringFlushIsNotLost()
    {
        $recursive = new class() extends AbstractHandler {
            /** @var LogRecord[] */
            public array $records = [];
            public ?ByteBufferHandler $bufferHandler = null;
            private bool $recursed = false;

            public function handle(LogRecord $record): bool
            {
                return false;
            }

            public function handleBatch(array $records): void
            {
                foreach ($records as $record) {
                    $this->records[] = $record;
                }
                if (!$this->recursed) {
                    $this->recursed = true;
                    // simulate a nested log write happening while flushing
                    $this->bufferHandler?->handle(new LogRecord(
                        datetime: new \DateTimeImmutable(),
                        channel: 'test',
                        level: Level::Info,
                        message: 'recursive'
                    ));
                }
            }
        };

        $handler = new ByteBufferHandler($recursive);
        $recursive->bufferHandler = $handler;

        $handler->handle($this->getRecord(Level::Info, 'initial'));
        $handler->flush();

        // the recursively written record must still be buffered, not wiped by the flush
        $this->assertSame(['initial'], array_map(fn (LogRecord $rec) => $rec->message, $recursive->records));
        $this->assertGreaterThan(0, $handler->getBufferByteSize());

        $handler->close();
        $this->assertSame(['initial', 'recursive'], array_map(fn (LogRecord $rec) => $rec->message, $recursive->records));
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::close
     */
    public function testCloseTwiceDoesNotRedeliverRecords()
    {
        $test = new TestHandler();
        $handler = new ByteBufferHandler($test);
        $handler->handle($this->getRecord(Level::Info, 'once'));

        $handler->close();
        $handler->close();

        $this->assertCount(1, $test->getRecords());
        $this->assertSame(0, $handler->getBufferByteSize());
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::handle
     */
    public function testHandleLevel()
    {
        $test = new TestHandler();
        $handler = new ByteBufferHandler($test, 0, Level::Info);
        $handler->handle($this->getRecord(Level::Debug));
        $handler->handle($this->getRecord(Level::Info));
        $handler->close();
        $this->assertTrue($test->hasInfoRecords());
        $this->assertFalse($test->hasDebugRecords());
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::handle
     */
    public function testHandleUsesProcessorsBeforeMeasuring()
    {
        $test = new TestHandler();
        $handler = new ByteBufferHandler($test);
        $handler->pushProcessor(function (LogRecord $record) {
            $record->extra['foo'] = '中文';

            return $record;
        });

        $record = $this->getRecord(Level::Warning);
        $handler->handle($record);

        $processed = $record->with(extra: ['foo' => '中文']);
        $this->assertSame($this->getRecordByteSize($processed), $handler->getBufferByteSize());

        $handler->flush();
        $records = $test->getRecords();
        $this->assertSame('中文', $records[0]->extra['foo']);
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::clear
     */
    public function testClearResetsByteSize()
    {
        $test = new TestHandler();
        $handler = new ByteBufferHandler($test);
        $handler->handle($this->getRecord(Level::Info, 'some record'));
        $this->assertGreaterThan(0, $handler->getBufferByteSize());

        $handler->clear();
        $this->assertSame(0, $handler->getBufferByteSize());

        $handler->close();
        $this->assertCount(0, $test->getRecords());
    }
}
