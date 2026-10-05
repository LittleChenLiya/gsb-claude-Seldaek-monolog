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
use Monolog\Test\MonologTestCase;

/**
 * @covers Monolog\Handler\EventTimeSortingHandler
 */
class EventTimeSortingHandlerTest extends MonologTestCase
{
    private function recordAt(int $timestamp, string $message, ?\DateTimeZone $tz = null): LogRecord
    {
        $dt = (new \DateTimeImmutable('@'.$timestamp))->setTimezone($tz ?? new \DateTimeZone('UTC'));

        return $this->getRecord(Level::Debug, $message, [], 'test', $dt);
    }

    /**
     * Times 10, 12, 11 with lateness 2: 10 is delivered when 12 arrives,
     * close delivers 11 then 12, and time 9 falls to the late callback.
     */
    public function testWatermarkDeliveryAndLateRecord(): void
    {
        $late = [];

        $underlying = new TestHandler();
        $handler = new EventTimeSortingHandler(
            $underlying,
            allowedLateness: 2,
            lateCallback: function (LogRecord $record) use (&$late) {
                $late[] = $record->message;
            }
        );

        $handler->handle($this->recordAt(10, 'ten'));
        $this->assertSame([], $underlying->getRecords(), 'nothing delivered before watermark advances');

        $handler->handle($this->recordAt(12, 'twelve'));
        $this->assertSame(['ten'], $this->messages($underlying->getRecords()), '10 delivered when watermark reaches 10');

        $handler->handle($this->recordAt(11, 'eleven'));
        $this->assertSame(['ten'], $this->messages($underlying->getRecords()), '11 still buffered');

        $handler->handle($this->recordAt(9, 'nine'));
        $this->assertSame(['nine'], $late, '9 is past the watermark and goes to the late callback');

        $handler->close();
        $this->assertSame(['ten', 'eleven', 'twelve'], $this->messages($underlying->getRecords()), 'close flushes 11 then 12');
    }

    /**
     * Records with the same event time are delivered in enqueue order.
     */
    public function testSameEventTimeOrderIsStable(): void
    {
        $underlying = new TestHandler();
        $handler = new EventTimeSortingHandler($underlying, allowedLateness: 2);

        $handler->handle($this->recordAt(10, 'first'));
        $handler->handle($this->recordAt(10, 'second'));
        $handler->handle($this->recordAt(12, 'third'));

        $handler->close();
        $this->assertSame(['first', 'second', 'third'], $this->messages($underlying->getRecords()));
    }

    /**
     * Two records representing the same UTC instant but carrying different
     * offsets compare equal and are delivered in enqueue order.
     */
    public function testSameUtcInstantDifferentOffsetsAreEqual(): void
    {
        $underlying = new TestHandler();
        $handler = new EventTimeSortingHandler($underlying, allowedLateness: 2);

        $utc = (new \DateTimeImmutable('@10'))->setTimezone(new \DateTimeZone('UTC'));
        $plus2 = (new \DateTimeImmutable('@10'))->setTimezone(new \DateTimeZone('+02:00'));

        $this->assertSame($utc->getTimestamp(), $plus2->getTimestamp());

        $handler->handle($this->getRecord(Level::Debug, 'utc', [], 'test', $utc));
        $handler->handle($this->getRecord(Level::Debug, 'plus2', [], 'test', $plus2));
        $handler->handle($this->recordAt(12, 'later'));

        $handler->close();
        $this->assertSame(['utc', 'plus2', 'later'], $this->messages($underlying->getRecords()));
    }

    /**
     * When the buffer limit is reached, new records are rejected and handed to
     * the late callback; rejected records do not advance the watermark.
     */
    public function testBufferLimitRejectsAndDoesNotAdvanceTime(): void
    {
        $late = [];
        $underlying = new TestHandler();
        $handler = new EventTimeSortingHandler(
            $underlying,
            allowedLateness: 2,
            bufferLimit: 2,
            lateCallback: function (LogRecord $record) use (&$late) {
                $late[] = $record->message;
            }
        );

        // 10 and 12 are accepted; 12 advances the watermark to 10 and delivers 10.
        $handler->handle($this->recordAt(10, 'ten'));
        $handler->handle($this->recordAt(12, 'twelve'));
        $this->assertSame(12, $handler->getMaxEventTime());
        $this->assertSame(10, $handler->getWatermark());
        $this->assertSame(['ten'], $this->messages($underlying->getRecords()));

        // 11 is accepted but does not advance the watermark (11 < 12).
        $handler->handle($this->recordAt(11, 'eleven'));
        $this->assertSame(12, $handler->getMaxEventTime());
        $this->assertSame(10, $handler->getWatermark());

        // Buffer is now full: 13 is rejected and must not advance the watermark.
        $handler->handle($this->recordAt(13, 'thirteen'));
        $this->assertSame(['thirteen'], $late);
        $this->assertSame(12, $handler->getMaxEventTime(), 'rejected record does not advance maxEventTime');
        $this->assertSame(10, $handler->getWatermark(), 'watermark stays at 12 - 2 = 10');
        $this->assertSame(['ten'], $this->messages($underlying->getRecords()), '11 not delivered (watermark still 10 < 11)');

        $handler->close();
        $this->assertSame(['ten', 'eleven', 'twelve'], $this->messages($underlying->getRecords()), 'close flushes 11 then 12');
    }

    /**
     * If the wrapped handler fails while processing a batch, the records are
     * marked as an unknown handoff and are not resent.
     */
    public function testFailureDoesNotResendOldRecords(): void
    {
        $underlying = new class extends TestHandler {
            public int $batchCalls = 0;
            public int $recordsDelivered = 0;

            public function handleBatch(array $records): void
            {
                $this->batchCalls++;
                $this->recordsDelivered += \count($records);
                throw new \RuntimeException('boom');
            }
        };

        $handler = new EventTimeSortingHandler($underlying, allowedLateness: 2);

        $handler->handle($this->recordAt(10, 'ten'));
        $handler->handle($this->recordAt(12, 'twelve')); // delivers 10 (batch 1, fails)
        $this->assertSame(1, $underlying->batchCalls);
        $this->assertSame(1, $underlying->recordsDelivered);

        // 14 makes 12 due; 10 must not be redelivered.
        $handler->handle($this->recordAt(14, 'fourteen'));
        $this->assertSame(2, $underlying->batchCalls, 'second batch attempted');
        $this->assertSame(2, $underlying->recordsDelivered, '10 not redelivered; only 12 added');

        $handler->close();
        $this->assertSame(3, $underlying->batchCalls, 'close flushes 14');
        $this->assertSame(3, $underlying->recordsDelivered, '10 still not redelivered');
    }

    /**
     * Close reports the unfinished (unknown handoff) records.
     */
    public function testCloseReportsUnfinishedQueue(): void
    {
        $unfinished = null;
        $failure = null;

        $underlying = new class extends TestHandler {
            public int $calls = 0;

            public function handleBatch(array $records): void
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new \RuntimeException('handoff failed');
                }
                parent::handleBatch($records);
            }
        };

        $handler = new EventTimeSortingHandler(
            $underlying,
            allowedLateness: 2,
            unfinishedCallback: function (array $records, ?\Throwable $e) use (&$unfinished, &$failure) {
                $unfinished = $records;
                $failure = $e;
            }
        );

        $handler->handle($this->recordAt(10, 'ten'));
        $handler->handle($this->recordAt(12, 'twelve')); // delivery of 10 fails

        $handler->close(); // 12 is delivered successfully

        $this->assertNotNull($unfinished, 'unfinished callback invoked at close');
        $this->assertSame(['ten'], $this->messages($unfinished));
        $this->assertInstanceOf(\RuntimeException::class, $failure);
        $this->assertSame(['ten'], $this->messages($handler->getUnfinishedRecords()), 'getUnfinishedRecords returns the unknown records');
        $this->assertNotNull($handler->getLastFailure());
    }

    /**
     * Close is idempotent: calling it twice does not invoke the unfinished
     * callback twice.
     */
    public function testCloseIsIdempotent(): void
    {
        $calls = 0;

        $underlying = new class extends TestHandler {
            public int $batchCalls = 0;

            public function handleBatch(array $records): void
            {
                $this->batchCalls++;
                throw new \RuntimeException('boom');
            }
        };

        $handler = new EventTimeSortingHandler(
            $underlying,
            allowedLateness: 2,
            unfinishedCallback: function () use (&$calls) {
                $calls++;
            }
        );

        $handler->handle($this->recordAt(10, 'ten'));
        $handler->handle($this->recordAt(12, 'twelve')); // delivery of 10 fails
        $handler->close();
        $handler->close();

        $this->assertSame(1, $calls, 'unfinished callback invoked exactly once');
    }

    /**
     * @param LogRecord[] $records
     * @return string[]
     */
    private function messages(array $records): array
    {
        return array_map(fn(LogRecord $r): string => $r->message, $records);
    }
}
