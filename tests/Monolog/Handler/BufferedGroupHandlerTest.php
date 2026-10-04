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

use Monolog\Level;
use Monolog\LogRecord;

class BufferedGroupHandlerTest extends \Monolog\Test\MonologTestCase
{
    /**
     * @covers Monolog\Handler\BufferedGroupHandler::__construct
     */
    public function testConstructorOnlyTakesHandler()
    {
        $this->expectException(\InvalidArgumentException::class);

        new BufferedGroupHandler([new TestHandler(), 'foo']);
    }

    /**
     * @covers Monolog\Handler\BufferedGroupHandler::handle
     * @covers Monolog\Handler\BufferedGroupHandler::flush
     */
    public function testBuffersRecordsPerTargetAndFlushes()
    {
        $targetA = new CountingHandler();
        $targetB = new CountingHandler();
        $handler = new BufferedGroupHandler([$targetA, $targetB]);

        $handler->handle($this->getRecord(Level::Debug, 'a'));
        $handler->handle($this->getRecord(Level::Info, 'b'));

        $this->assertSame(0, $targetA->batchCount);
        $this->assertSame(0, $targetB->batchCount);

        $handler->flush();

        foreach ([$targetA, $targetB] as $target) {
            $this->assertSame(1, $target->batchCount);
            $this->assertCount(2, $target->batches[0]);
            $this->assertSame(['a', 'b'], $this->messages($target->batches[0]));
        }
    }

    /**
     * @covers Monolog\Handler\BufferedGroupHandler::handleBatch
     * @covers Monolog\Handler\BufferedGroupHandler::flush
     */
    public function testHandleBatchBuffersOneBatchPerTarget()
    {
        $target = new CountingHandler();
        $handler = new BufferedGroupHandler([$target]);

        $handler->handleBatch([$this->getRecord(Level::Debug, 'a'), $this->getRecord(Level::Info, 'b')]);

        $this->assertSame(0, $target->batchCount);

        $handler->flush();

        $this->assertSame(1, $target->batchCount);
        $this->assertSame(['a', 'b'], $this->messages($target->batches[0]));
    }

    /**
     * Acceptance scenario:
     *  - target A succeeds on the first flush, target B fails;
     *  - on the next flush A is not sent again (1 call) while B is retried
     *    with the exact same batch (2 calls total, identical content);
     *  - records added after the failure are delivered to B as a separate
     *    batch, after the successful retry.
     *
     * @covers Monolog\Handler\BufferedGroupHandler::flush
     * @covers Monolog\Handler\BufferedGroupHandler::flushTarget
     */
    public function testFlushRetriesOnlyFailedTargetsWithIdenticalBatchAndNextBatch()
    {
        $targetA = new CountingHandler();
        $targetB = new CountingHandler();
        $targetB->failTimes = 1;

        $handler = new BufferedGroupHandler([$targetA, $targetB]);
        $handler->handle($this->getRecord(Level::Debug, 'a'));
        $handler->handle($this->getRecord(Level::Info, 'b'));

        // first flush: A succeeds, B fails while sending its first batch
        $handler->flush();

        $this->assertSame(1, $targetA->batchCount, 'successful target must not be retried');
        $this->assertSame(1, $targetB->batchCount);
        $this->assertTrue($handler->hasFailures());
        $errors = $handler->getErrors();
        $this->assertArrayHasKey(1, $errors);
        $this->assertInstanceOf(\RuntimeException::class, $errors[1]);
        $this->assertSame(['a', 'b'], $this->messages($targetB->batches[0]));

        // records arriving during the failure window form a new batch
        $handler->handle($this->getRecord(Level::Warning, 'c'));

        // second flush: A must not be sent again, B only retries the old batch
        $handler->flush();

        $this->assertSame(1, $targetA->batchCount, 'successful target must never be sent again');
        $this->assertSame(2, $targetB->batchCount, 'failed target must be retried exactly once');
        $this->assertSame(['a', 'b'], $this->messages($targetB->batches[1]), 'retried batch content must be identical');
        $this->assertTrue($handler->hasFailures() === false);
        $this->assertSame([], $handler->getErrors());

        // third flush: once B has recovered, the records accumulated during
        // the failure window are sent as the next batch
        $handler->flush();

        $this->assertSame(2, $targetA->batchCount);
        $this->assertSame(3, $targetB->batchCount);
        $this->assertSame(['c'], $this->messages($targetB->batches[2]), 'new records must go out as the next batch');
        $this->assertSame(['c'], $this->messages($targetA->batches[1]));
        $this->assertFalse($handler->hasFailures());
    }

    /**
     * A handler that mutates records before throwing must not be able to alter
     * the buffered batch that will be retried: the retry must receive a fresh,
     * unmutated clone with the exact same content.
     *
     * @covers Monolog\Handler\BufferedGroupHandler::flushTarget
     */
    public function testFailedBatchContentUnaffectedByMutatingHandler()
    {
        $target = new CountingHandler();
        $target->failTimes = 1;
        $target->mutate = true;

        $handler = new BufferedGroupHandler([$target]);
        $handler->handle($this->getRecord(Level::Debug, 'a'));
        $handler->flush();

        $this->assertSame(1, $target->batchCount);
        $this->assertTrue($handler->hasFailures());
        // the first attempt received a fresh record (cloned at delivery)
        $this->assertSame([[]], $target->incomingExtra[0]);

        $handler->flush();

        $this->assertSame(2, $target->batchCount);
        $this->assertSame(['a'], $this->messages($target->batches[1]), 'retry must re-send the same batch');
        // the retry also received a fresh record, not the one mutated by the first attempt
        $this->assertSame([[]], $target->incomingExtra[1], 'retry must receive a fresh, unmutated record');
        $this->assertFalse($handler->hasFailures());
    }

    /**
     * Records are cloned per target, so a handler mutating a record must not
     * affect the records buffered for the other targets.
     *
     * @covers Monolog\Handler\BufferedGroupHandler::bufferRecord
     */
    public function testRecordsAreClonedPerTarget()
    {
        $targetA = new CountingHandler();
        $targetA->mutate = true;
        $targetB = new CountingHandler();

        $handler = new BufferedGroupHandler([$targetA, $targetB]);
        $handler->handle($this->getRecord(Level::Debug, 'a'));
        $handler->flush();

        foreach ($targetB->batches[0] as $record) {
            $this->assertSame([], $record->extra, 'target A mutations must not leak to target B');
        }
    }

    /**
     * @covers Monolog\Handler\BufferedGroupHandler::bufferRecord
     * @covers Monolog\Handler\BufferedGroupHandler::discard
     */
    public function testBufferLimitDiscardsOldestWithCallbackAndCount()
    {
        $targetA = new CountingHandler();
        $targetB = new CountingHandler();
        $discards = [];

        $handler = new BufferedGroupHandler(
            [$targetA, $targetB],
            bufferLimit: 2,
            onDiscard: function (int $count, HandlerInterface $targetHandler, int $index) use (&$discards): void {
                $discards[] = [$count, $index, $targetHandler];
            },
        );

        $handler->handle($this->getRecord(Level::Debug, 'a'));
        $handler->handle($this->getRecord(Level::Debug, 'b'));
        $handler->handle($this->getRecord(Level::Info, 'c'));

        $this->assertCount(2, $discards);
        foreach ($discards as [$count, $index, $targetHandler]) {
            $this->assertSame(1, $count);
            $this->assertContains($index, [0, 1]);
            $this->assertContains($targetHandler, [$targetA, $targetB]);
        }
        $this->assertSame(1, $handler->getDiscardCount(0));
        $this->assertSame(1, $handler->getDiscardCount(1));
        $this->assertSame([0 => 1, 1 => 1], $handler->getDiscardCounts());

        $handler->flush();

        foreach ([$targetA, $targetB] as $target) {
            $this->assertSame(['b', 'c'], $this->messages($target->batches[0]));
        }
    }

    /**
     * @covers Monolog\Handler\BufferedGroupHandler::handle
     */
    public function testLevelFiltersBufferedRecords()
    {
        $target = new CountingHandler();
        $handler = new BufferedGroupHandler([$target], level: Level::Warning);

        $handler->handle($this->getRecord(Level::Debug, 'a'));
        $handler->handle($this->getRecord(Level::Warning, 'b'));
        $handler->handle($this->getRecord(Level::Info, 'c'));
        $handler->handleBatch([$this->getRecord(Level::Error, 'd'), $this->getRecord(Level::Debug, 'e')]);
        $handler->flush();

        $this->assertSame(['b', 'd'], $this->messages($target->batches[0]));
    }

    /**
     * @covers Monolog\Handler\BufferedGroupHandler::isHandling
     */
    public function testIsHandling()
    {
        $handler = new BufferedGroupHandler([new TestHandler(Level::Error), new TestHandler(Level::Warning)]);

        $this->assertTrue($handler->isHandling($this->getRecord(Level::Error)));
        $this->assertTrue($handler->isHandling($this->getRecord(Level::Warning)));
        $this->assertFalse($handler->isHandling($this->getRecord(Level::Debug)));
    }

    /**
     * @covers Monolog\Handler\BufferedGroupHandler::close
     * @covers Monolog\Handler\BufferedGroupHandler::flush
     */
    public function testCloseFlushesAndClosesTargets()
    {
        $targetA = new CountingHandler();
        $targetB = new CountingHandler();
        $handler = new BufferedGroupHandler([$targetA, $targetB]);

        $handler->handle($this->getRecord(Level::Debug, 'a'));
        $handler->close();

        foreach ([$targetA, $targetB] as $target) {
            $this->assertSame(1, $target->batchCount);
            $this->assertTrue($target->closed);
        }

        // close must be idempotent and not send the batches again
        $handler->close();
        foreach ([$targetA, $targetB] as $target) {
            $this->assertSame(1, $target->batchCount);
        }
    }

    /**
     * @covers Monolog\Handler\BufferedGroupHandler::close
     * @covers Monolog\Handler\BufferedGroupHandler::flush
     */
    public function testCloseRetriesFailedTargetAndSendsHeldBuffers()
    {
        $targetA = new CountingHandler();
        $targetB = new CountingHandler();
        $targetB->failTimes = 1;

        $handler = new BufferedGroupHandler([$targetA, $targetB]);
        $handler->handle($this->getRecord(Level::Debug, 'a'));
        $handler->flush();

        $this->assertSame(1, $targetA->batchCount);
        $this->assertSame(1, $targetB->batchCount);

        // records accumulated during the failure window
        $handler->handle($this->getRecord(Level::Info, 'b'));
        $handler->close();

        // close force-flushes: A sends its held record, B retries then sends its next batch
        $this->assertSame(2, $targetA->batchCount);
        $this->assertSame(3, $targetB->batchCount);
        $this->assertSame(['a'], $this->messages($targetB->batches[0]));
        $this->assertSame(['a'], $this->messages($targetB->batches[1]), 'retry must re-send the same batch');
        $this->assertSame(['b'], $this->messages($targetB->batches[2]), 'close must also send the accumulated next batch');
        $this->assertTrue($targetA->closed);
        $this->assertTrue($targetB->closed);
        $this->assertFalse($handler->hasFailures());
    }

    /**
     * @covers Monolog\Handler\BufferedGroupHandler::clear
     */
    public function testClearDiscardsBuffersWithoutSending()
    {
        $target = new CountingHandler();
        $handler = new BufferedGroupHandler([$target]);

        $handler->handle($this->getRecord(Level::Debug, 'a'));
        $handler->clear();
        $handler->flush();

        $this->assertSame(0, $target->batchCount);
    }

    /**
     * @covers Monolog\Handler\BufferedGroupHandler::reset
     */
    public function testResetFlushesBuffers()
    {
        $target = new CountingHandler();
        $handler = new BufferedGroupHandler([$target]);

        $handler->handle($this->getRecord(Level::Debug, 'a'));
        $handler->reset();

        $this->assertSame(1, $target->batchCount);
        $this->assertSame(['a'], $this->messages($target->batches[0]));
    }

    /**
     * @covers Monolog\Handler\BufferedGroupHandler::handle
     */
    public function testProcessorsRunOnceAndApplyToAllTargets()
    {
        $targetA = new CountingHandler();
        $targetB = new CountingHandler();
        $handler = new BufferedGroupHandler([$targetA, $targetB]);
        $handler->pushProcessor(function (LogRecord $record): LogRecord {
            $record->extra['foo'] = 'bar';

            return $record;
        });

        $handler->handle($this->getRecord(Level::Debug, 'a'));
        $handler->flush();

        foreach ([$targetA, $targetB] as $target) {
            $this->assertSame('bar', $target->batches[0][0]->extra['foo']);
        }
    }

    /**
     * @return string[]
     */
    private function messages(array $records): array
    {
        return array_map(static fn (LogRecord $record): string => $record->message, $records);
    }
}

/**
 * Test double counting batch deliveries and optionally failing or mutating records.
 */
class CountingHandler extends \Monolog\Handler\Handler
{
    public int $batchCount = 0;

    public int $recordCount = 0;

    /** @var array<int, array<LogRecord>> */
    public array $batches = [];

    /**
     * The extra data of the records as they were received by each delivery,
     * before the handler had a chance to mutate them.
     *
     * @var array<int, array<int, array<mixed>>>
     */
    public array $incomingExtra = [];

    public int $failTimes = 0;

    public bool $mutate = false;

    public bool $closed = false;

    public function isHandling(LogRecord $record): bool
    {
        return true;
    }

    public function handle(LogRecord $record): bool
    {
        $this->recordCount++;

        return false;
    }

    public function handleBatch(array $records): void
    {
        $this->batchCount++;
        $this->batches[] = $records;
        $this->incomingExtra[] = array_map(static fn (LogRecord $record): array => $record->extra, $records);

        foreach ($records as $record) {
            if ($this->mutate) {
                $record->extra['mutated_by'] = static::class;
            }
        }

        if ($this->failTimes > 0) {
            $this->failTimes--;
            throw new \RuntimeException('simulated delivery failure');
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
