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
use Monolog\Utils;

class ByteBufferHandlerTest extends \Monolog\Test\MonologTestCase
{
    private function serializedSize(LogRecord $record): int
    {
        return \strlen(Utils::jsonEncode((new NormalizerFormatter())->format($record), null, true));
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::__construct
     * @covers Monolog\Handler\ByteBufferHandler::handle
     * @covers Monolog\Handler\ByteBufferHandler::close
     */
    public function testHandleBuffers()
    {
        $test = new ByteBufferTestHandler();
        $handler = new ByteBufferHandler($test);
        $handler->handle($this->getRecord(Level::Debug));
        $handler->handle($this->getRecord(Level::Info));
        $this->assertCount(0, $test->batches);
        $handler->close();
        $this->assertCount(1, $test->batches);
        $this->assertCount(2, $test->batches[0]);
        $this->assertSame(Level::Debug, $test->batches[0][0]->level);
        $this->assertSame(Level::Info, $test->batches[0][1]->level);
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::handle
     * @covers Monolog\Handler\ByteBufferHandler::getBufferedSize
     */
    public function testByteAccountingUsesUtf8BytesNotCharacters()
    {
        $test = new ByteBufferTestHandler();
        $handler = new ByteBufferHandler($test);

        $record = $this->getRecord(Level::Warning, '中文日志消息 déjà', [
            'user' => '用户名',
            'tags' => array_fill(0, 200, '中文标签'),
            'payload' => str_repeat('很大的上下文数据', 100),
        ]);

        $json = Utils::jsonEncode((new NormalizerFormatter())->format($record), null, true);
        // sanity check: the record contains multi-byte chars so its UTF-8 byte
        // length is strictly larger than its character count
        $this->assertGreaterThan(\mb_strlen($json), \strlen($json));

        $handler->handle($record);

        // the buffered size must match the serialized UTF-8 byte count exactly
        $this->assertSame(\strlen($json), $handler->getBufferedSize());
        $this->assertSame($this->serializedSize($record), $handler->getBufferedSize());
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::handle
     */
    public function testByteLimitIsEnforcedBeforeAppending()
    {
        $test = new ByteBufferTestHandler();

        $record1 = $this->getRecord(Level::Info, '第一条');
        $record2 = $this->getRecord(Level::Info, '第二条');
        $record3 = $this->getRecord(Level::Info, '第三条');
        $size1 = $this->serializedSize($record1);
        $size2 = $this->serializedSize($record2);
        $size3 = $this->serializedSize($record3);

        // fits record1 + record2 exactly, but not one more byte
        $handler = new ByteBufferHandler($test, $size1 + $size2);

        $handler->handle($record1);
        $handler->handle($record2);
        $this->assertSame($size1 + $size2, $handler->getBufferedSize());

        // rejected: the buffer must not exceed the limit even temporarily
        $handler->handle($record3);
        $this->assertSame($size1 + $size2, $handler->getBufferedSize());
        $this->assertCount(0, $test->batches);

        $handler->close();
        $this->assertCount(1, $test->batches);
        $this->assertSame(['第一条', '第二条'], array_map(fn ($record) => $record->message, $test->batches[0]));
        $this->assertSame(0, $handler->getBufferedSize());
        $this->assertGreaterThan(0, $size3);
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::handle
     * @covers Monolog\Handler\ByteBufferHandler::flush
     */
    public function testFlushOnOverflowFlushesOldRecordsFirst()
    {
        $test = new ByteBufferTestHandler();

        $record1 = $this->getRecord(Level::Info, 'one');
        $record2 = $this->getRecord(Level::Info, 'two');
        $record3 = $this->getRecord(Level::Info, 'three');
        $limit = $this->serializedSize($record1) + $this->serializedSize($record2);

        $handler = new ByteBufferHandler($test, $limit, Level::Debug, true, true);

        $handler->handle($record1);
        $handler->handle($record2);
        $this->assertCount(0, $test->batches);

        // appending record3 would exceed the limit: the old batch is flushed first
        $handler->handle($record3);
        $this->assertCount(1, $test->batches);
        $this->assertSame(['one', 'two'], array_map(fn ($record) => $record->message, $test->batches[0]));
        $this->assertSame($this->serializedSize($record3), $handler->getBufferedSize());

        $handler->close();
        $this->assertCount(2, $test->batches);
        $this->assertSame(['three'], array_map(fn ($record) => $record->message, $test->batches[1]));
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::handle
     */
    public function testRejectOnOverflowDropsNewRecords()
    {
        $test = new ByteBufferTestHandler();

        $record1 = $this->getRecord(Level::Info, 'one');
        $record2 = $this->getRecord(Level::Info, 'two');
        $record3 = $this->getRecord(Level::Info, 'six');
        // larger than the space left after record1 but still within the limit
        $big = $this->getRecord(Level::Info, str_repeat('r', 50));
        $limit = $this->serializedSize($record1) + $this->serializedSize($record2);

        $this->assertLessThanOrEqual($limit, $this->serializedSize($big));
        $this->assertGreaterThan($limit - $this->serializedSize($record1), $this->serializedSize($big));

        $handler = new ByteBufferHandler($test, $limit);

        $handler->handle($record1);
        // does not fit anymore and must be rejected
        $handler->handle($big);
        // a smaller record still fits in the remaining space
        $handler->handle($record2);
        // buffer is exactly full now, nothing else is accepted
        $handler->handle($record3);

        $this->assertSame($limit, $handler->getBufferedSize());

        $handler->close();
        $this->assertCount(1, $test->batches);
        $this->assertSame(['one', 'two'], array_map(fn ($record) => $record->message, $test->batches[0]));
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::flush
     */
    public function testFailingHandlerRetainsBatchForRetry()
    {
        $test = new ByteBufferTestHandler();
        $test->failuresRemaining = 1;

        $record1 = $this->getRecord(Level::Info, 'one');
        $record2 = $this->getRecord(Level::Info, 'two');
        $record3 = $this->getRecord(Level::Info, 'three');
        $limit = $this->serializedSize($record1) + $this->serializedSize($record2);

        $handler = new ByteBufferHandler($test, $limit, Level::Debug, true, true);

        $handler->handle($record1);
        $handler->handle($record2);

        // the overflow flush fails: the exception propagates and the batch is retained
        try {
            $handler->handle($record3);
            $this->fail('Expected the handler failure to propagate');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated failure', $exception->getMessage());
        }

        $this->assertSame($limit, $handler->getBufferedSize());
        $this->assertCount(0, $test->batches);

        // retrying the flush delivers the retained batch, in order
        $handler->flush();
        $this->assertCount(1, $test->batches);
        $this->assertSame(['one', 'two'], array_map(fn ($record) => $record->message, $test->batches[0]));

        // the handler recovers and keeps buffering afterwards
        $handler->handle($record3);
        $handler->close();
        $this->assertCount(2, $test->batches);
        $this->assertSame(['three'], array_map(fn ($record) => $record->message, $test->batches[1]));
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::handle
     */
    public function testOversizedSingleRecordPassesThroughImmediately()
    {
        $test = new ByteBufferTestHandler();

        $record1 = $this->getRecord(Level::Info, 'small');
        $oversized = $this->getRecord(Level::Warning, str_repeat('超大单条记录', 100), ['context' => str_repeat('大量上下文', 100)]);
        $limit = $this->serializedSize($record1) + 10;

        $this->assertGreaterThan($limit, $this->serializedSize($oversized));

        $handler = new ByteBufferHandler($test, $limit, Level::Debug, true, true);

        $handler->handle($record1);
        $this->assertCount(0, $test->batches);

        // pending records are flushed first, then the oversized record is
        // delivered on its own without ever sitting in the buffer
        $handler->handle($oversized);
        $this->assertCount(2, $test->batches);
        $this->assertSame(['small'], array_map(fn ($record) => $record->message, $test->batches[0]));
        $this->assertSame([$oversized->message], array_map(fn ($record) => $record->message, $test->batches[1]));
        $this->assertSame(0, $handler->getBufferedSize());

        $handler->close();
        $this->assertCount(2, $test->batches);
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::flush
     */
    public function testRecursiveWriteDuringFlushIsNotLost()
    {
        $test = new ByteBufferTestHandler();
        $handler = new ByteBufferHandler($test);

        $reentrant = $this->getRecord(Level::Info, 'reentrant');
        $triggered = false;
        $test->onHandleBatch = function () use ($handler, $reentrant, &$triggered) {
            if (!$triggered) {
                $triggered = true;
                // the wrapped handler logs back into the buffer while it is flushing
                $handler->handle($reentrant);
            }
        };

        $handler->handle($this->getRecord(Level::Warning, 'initial'));
        $handler->flush();

        // the re-entrant record starts a fresh batch instead of being wiped out
        $this->assertCount(1, $test->batches);
        $this->assertSame(['initial'], array_map(fn ($record) => $record->message, $test->batches[0]));
        $this->assertSame($this->serializedSize($reentrant), $handler->getBufferedSize());

        $handler->flush();
        $this->assertCount(2, $test->batches);
        $this->assertSame(['reentrant'], array_map(fn ($record) => $record->message, $test->batches[1]));
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::close
     */
    public function testDoubleCloseDoesNotRedeliver()
    {
        $test = new ByteBufferTestHandler();
        $handler = new ByteBufferHandler($test);
        $handler->handle($this->getRecord(Level::Warning));

        $handler->close();
        $handler->close();

        $this->assertCount(1, $test->batches);
        $this->assertCount(1, $test->batches[0]);
        $this->assertSame(2, $test->closeCount);
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::handle
     */
    public function testHandleLevel()
    {
        $test = new ByteBufferTestHandler();
        $handler = new ByteBufferHandler($test, 0, Level::Info);
        $handler->handle($this->getRecord(Level::Debug));
        $handler->handle($this->getRecord(Level::Info));
        $handler->close();
        $this->assertCount(1, $test->batches);
        $this->assertCount(1, $test->batches[0]);
        $this->assertSame(Level::Info, $test->batches[0][0]->level);
    }

    /**
     * @covers Monolog\Handler\ByteBufferHandler::handle
     */
    public function testHandleUsesProcessorsBeforeMeasuring()
    {
        $test = new ByteBufferTestHandler();
        $handler = new ByteBufferHandler($test);
        $handler->pushProcessor(function ($record) {
            $record->extra['foo'] = '中文额外数据';

            return $record;
        });

        $record = $this->getRecord(Level::Warning);
        $handler->handle($record);

        $processed = $record->with(extra: ['foo' => '中文额外数据']);
        $this->assertSame($this->serializedSize($processed), $handler->getBufferedSize());

        $handler->close();
        $this->assertSame('中文额外数据', $test->batches[0][0]->extra['foo']);
    }
}

class ByteBufferTestHandler extends Handler
{
    /** @var LogRecord[][] */
    public array $batches = [];

    public int $failuresRemaining = 0;

    public int $closeCount = 0;

    public ?\Closure $onHandleBatch = null;

    public function isHandling(LogRecord $record): bool
    {
        return true;
    }

    public function handle(LogRecord $record): bool
    {
        return false;
    }

    public function handleBatch(array $records): void
    {
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;

            throw new \RuntimeException('Simulated failure');
        }

        $this->batches[] = $records;

        if (null !== $this->onHandleBatch) {
            ($this->onHandleBatch)($records);
        }
    }

    public function close(): void
    {
        $this->closeCount++;
    }
}
