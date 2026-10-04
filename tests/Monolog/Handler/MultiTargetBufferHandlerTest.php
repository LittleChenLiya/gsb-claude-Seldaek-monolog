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

/**
 * Records every handleBatch call and can be told to fail (optionally
 * tampering with the records it received before throwing).
 */
class RecordingBatchHandler extends Handler
{
    /** @var LogRecord[][] one entry per handleBatch call, failed attempts included */
    public array $batches = [];

    /** @var \Throwable[] queue of exceptions to throw on the next handleBatch calls */
    public array $failures = [];

    public bool $tamperBeforeFailing = false;

    public int $closeCount = 0;

    public function isHandling(LogRecord $record): bool
    {
        return true;
    }

    public function handle(LogRecord $record): bool
    {
        $this->handleBatch([$record]);

        return false;
    }

    public function handleBatch(array $records): void
    {
        $this->batches[] = $records;

        if (\count($this->failures) > 0) {
            $failure = array_shift($this->failures);
            if ($this->tamperBeforeFailing) {
                foreach ($records as $record) {
                    $record->extra['tampered'] = true;
                    $record->formatted = 'tampered';
                }
            }

            throw $failure;
        }
    }

    public function close(): void
    {
        $this->closeCount++;
    }

    /** @return string[] */
    public function messagesOf(int $batchIndex): array
    {
        return array_map(fn (LogRecord $record) => $record->message, $this->batches[$batchIndex]);
    }
}

class MultiTargetBufferHandlerTest extends \Monolog\Test\MonologTestCase
{
    public function testConstructorOnlyTakesHandlers()
    {
        $this->expectException(\InvalidArgumentException::class);

        new MultiTargetBufferHandler([new TestHandler(), 'foo']);
    }

    public function testConstructorRequiresAtLeastOneHandler()
    {
        $this->expectException(\InvalidArgumentException::class);

        new MultiTargetBufferHandler([]);
    }

    public function testHandleBuffersPerTargetUntilFlush()
    {
        $a = new RecordingBatchHandler();
        $b = new RecordingBatchHandler();
        $handler = new MultiTargetBufferHandler(['a' => $a, 'b' => $b]);

        $handler->handle($this->getRecord(Level::Debug, 'one'));
        $handler->handle($this->getRecord(Level::Info, 'two'));

        $this->assertCount(0, $a->batches);
        $this->assertCount(0, $b->batches);
        $this->assertSame(2, $handler->getBufferSize('a'));
        $this->assertSame(2, $handler->getBufferSize('b'));

        $handler->flush();

        $this->assertSame(['one', 'two'], $a->messagesOf(0));
        $this->assertSame(['one', 'two'], $b->messagesOf(0));
        $this->assertSame(0, $handler->getBufferSize('a'));
        $this->assertSame(0, $handler->getBufferSize('b'));
    }

    /**
     * Acceptance scenario: target A succeeds, target B fails. The next
     * flush must not re-send anything to A, must retry B with identical
     * content, and records logged after the failure must go out as B's
     * next batch after the successful retry.
     */
    public function testFailedTargetIsRetriedAloneWithIdenticalBatch()
    {
        $a = new RecordingBatchHandler();
        $b = new RecordingBatchHandler();
        $b->failures[] = new \RuntimeException('B is down');

        $handler = new MultiTargetBufferHandler(['a' => $a, 'b' => $b]);
        $handler->handle($this->getRecord(Level::Warning, 'first'));
        $handler->handle($this->getRecord(Level::Warning, 'second'));

        $handler->flush();

        $this->assertCount(1, $a->batches);
        $this->assertCount(1, $b->batches);
        $this->assertInstanceOf(\RuntimeException::class, $handler->getError('b'));
        $this->assertNull($handler->getError('a'));
        $this->assertSame(['first', 'second'], $b->messagesOf(0));

        // second flush: A is not bothered again, B retries the failed batch
        $handler->flush();

        $this->assertCount(1, $a->batches, 'Target A must not receive its batch again');
        $this->assertCount(2, $b->batches, 'Target B must have retried once');
        $this->assertSame($b->messagesOf(0), $b->messagesOf(1), 'Retried batch content must be identical');
        $this->assertNull($handler->getError('b'));
        $this->assertNull($handler->getPendingBatch('b'));

        // records logged after the failure go out as the next batch
        $handler->handle($this->getRecord(Level::Warning, 'third'));
        $handler->flush();

        $this->assertCount(2, $a->batches);
        $this->assertSame(['third'], $a->messagesOf(1));
        $this->assertCount(3, $b->batches);
        $this->assertSame(['third'], $b->messagesOf(2), 'New records must be sent as a separate batch after the retry');
    }

    /**
     * Records arriving while a target has a pending failed batch must not
     * be merged into the pending batch: the retry keeps its exact original
     * content and the new records are sent as a later, separate batch.
     */
    public function testRecordsArrivingDuringFailureDoNotAlterPendingBatch()
    {
        $b = new RecordingBatchHandler();
        $b->failures[] = new \RuntimeException('down 1');
        $b->failures[] = new \RuntimeException('down 2');

        $handler = new MultiTargetBufferHandler(['b' => $b]);
        $handler->handle($this->getRecord(Level::Warning, 'first'));
        $handler->handle($this->getRecord(Level::Warning, 'second'));

        $handler->flush(); // fails, batch [first, second] becomes pending
        $this->assertCount(2, $handler->getPendingBatch('b'));

        // arrives while the failure is pending
        $handler->handle($this->getRecord(Level::Warning, 'third'));

        $handler->flush(); // retries [first, second] only, fails again
        $this->assertCount(2, $b->batches);
        $this->assertSame(['first', 'second'], $b->messagesOf(1));
        $this->assertCount(2, $handler->getPendingBatch('b'), 'Pending batch must not absorb new records');
        $this->assertSame(1, $handler->getBufferSize('b'));

        $handler->flush(); // retry finally succeeds, still only [first, second]
        $this->assertCount(3, $b->batches);
        $this->assertSame(['first', 'second'], $b->messagesOf(2));
        $this->assertNull($handler->getPendingBatch('b'));

        $handler->flush(); // now the records accumulated during the outage go out
        $this->assertCount(4, $b->batches);
        $this->assertSame(['third'], $b->messagesOf(3));
    }

    /**
     * The handler enforces immutability itself: neither caller-side
     * mutation after handle() nor tampering by a failing nested handler
     * may leak into the buffered/retried batch.
     */
    public function testBufferedAndRetriedRecordsAreImmutable()
    {
        $b = new RecordingBatchHandler();
        $b->tamperBeforeFailing = true;
        $b->failures[] = new \RuntimeException('down');

        $handler = new MultiTargetBufferHandler(['b' => $b]);

        $record = $this->getRecord(Level::Warning, 'first');
        $handler->handle($record);

        // caller mutates the record after it was handled
        $record->extra['caller'] = 'mutation';
        $record->formatted = 'caller mutation';

        $handler->flush(); // failing handler tampers with what it received, then throws
        $handler->flush(); // retry

        $this->assertCount(2, $b->batches);
        $retried = $b->batches[1][0];
        $this->assertSame('first', $retried->message);
        $this->assertArrayNotHasKey('caller', $retried->extra, 'Caller mutation after handle() must not leak into the buffer');
        $this->assertArrayNotHasKey('tampered', $retried->extra, 'Tampering by the failing handler must not leak into the retry');
        $this->assertNull($retried->formatted);
    }

    public function testBufferLimitDiscardsOldestAndReportsViaCallback()
    {
        $a = new RecordingBatchHandler();
        $b = new RecordingBatchHandler();

        $drops = [];
        $onOverflow = function (int|string $target, LogRecord $dropped, int $discardedCount) use (&$drops) {
            $drops[] = [$target, $dropped->message, $discardedCount];
        };

        $handler = new MultiTargetBufferHandler(['a' => $a, 'b' => $b], 2, Level::Debug, true, $onOverflow);
        $handler->handle($this->getRecord(Level::Warning, 'one'));
        $handler->handle($this->getRecord(Level::Warning, 'two'));
        $handler->handle($this->getRecord(Level::Warning, 'three'));
        $handler->handle($this->getRecord(Level::Warning, 'four'));

        $this->assertSame(2, $handler->getDiscardedCount('a'));
        $this->assertSame(2, $handler->getDiscardedCount('b'));
        $this->assertSame(
            [['a', 'one', 1], ['b', 'one', 1], ['a', 'two', 2], ['b', 'two', 2]],
            $drops,
            'Every discarded record must be reported with its target and the running discard count'
        );

        $handler->flush();

        $this->assertSame(['three', 'four'], $a->messagesOf(0));
        $this->assertSame(['three', 'four'], $b->messagesOf(0));
    }

    public function testCloseFlushesAndClosesTargets()
    {
        $a = new RecordingBatchHandler();
        $b = new RecordingBatchHandler();
        $handler = new MultiTargetBufferHandler(['a' => $a, 'b' => $b]);

        $handler->handle($this->getRecord(Level::Warning, 'one'));
        $handler->close();

        $this->assertSame(['one'], $a->messagesOf(0));
        $this->assertSame(['one'], $b->messagesOf(0));
        $this->assertSame(1, $a->closeCount);
        $this->assertSame(1, $b->closeCount);
    }

    public function testHandleBatchBuffersEveryRecord()
    {
        $a = new RecordingBatchHandler();
        $handler = new MultiTargetBufferHandler(['a' => $a]);

        $handler->handleBatch([
            $this->getRecord(Level::Warning, 'one'),
            $this->getRecord(Level::Warning, 'two'),
        ]);
        $handler->flush();

        $this->assertSame(['one', 'two'], $a->messagesOf(0));
    }

    public function testHandleRespectsLevelAndBubble()
    {
        $a = new RecordingBatchHandler();
        $handler = new MultiTargetBufferHandler(['a' => $a], 0, Level::Warning, false);

        $this->assertFalse($handler->handle($this->getRecord(Level::Debug)));
        $this->assertTrue($handler->handle($this->getRecord(Level::Error)));
        $this->assertSame(1, $handler->getBufferSize('a'));
        $this->assertFalse($handler->isHandling($this->getRecord(Level::Debug)));
        $this->assertTrue($handler->isHandling($this->getRecord(Level::Error)));
    }

    public function testClearDropsBuffersAndPendingBatches()
    {
        $b = new RecordingBatchHandler();
        $b->failures[] = new \RuntimeException('down');

        $handler = new MultiTargetBufferHandler(['b' => $b]);
        $handler->handle($this->getRecord(Level::Warning, 'one'));
        $handler->flush();
        $handler->handle($this->getRecord(Level::Warning, 'two'));

        $handler->clear();

        $this->assertSame(0, $handler->getBufferSize('b'));
        $this->assertNull($handler->getPendingBatch('b'));
        $this->assertNull($handler->getError('b'));

        $handler->flush();
        $this->assertCount(1, $b->batches, 'Nothing may be sent after clear()');
    }

    public function testUnknownTargetAccessThrows()
    {
        $handler = new MultiTargetBufferHandler(['a' => new RecordingBatchHandler()]);

        $this->expectException(\InvalidArgumentException::class);

        $handler->getBufferSize('nope');
    }
}
