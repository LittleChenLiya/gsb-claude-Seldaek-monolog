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

class EventTimeOrderHandlerTest extends \Monolog\Test\MonologTestCase
{
    private function recordAt(int $epochSeconds, string $message): LogRecord
    {
        return $this->getRecord(Level::Info, $message, datetime: new \DateTimeImmutable('@'.$epochSeconds));
    }

    /**
     * @return list<string>
     */
    private function messages(TestHandler $handler): array
    {
        return array_map(static fn (LogRecord $record): string => $record->message, $handler->getRecords());
    }

    /**
     * Acceptance scenario: times 10, 12, 11 with allowed lateness 2 deliver 10
     * as soon as the watermark passes it, and close() flushes 11 then 12 in
     * event-time order.
     */
    public function testWatermarkOrderingAndCloseFlush()
    {
        $test = new TestHandler();
        $handler = new EventTimeOrderHandler($test, allowedLateness: 2.0);

        $handler->handle($this->recordAt(10, 'ten'));
        $this->assertSame([], $this->messages($test), '10 is above watermark 8, nothing delivered yet');

        $handler->handle($this->recordAt(12, 'twelve'));
        $this->assertSame(['ten'], $this->messages($test), 'watermark reached 10, so 10 is delivered');

        $handler->handle($this->recordAt(11, 'eleven'));
        $this->assertSame(['ten'], $this->messages($test), '11 is above watermark 10, still buffered');

        $handler->close();
        $this->assertSame(['ten', 'eleven', 'twelve'], $this->messages($test), 'close() flushes the rest in event-time order');
        $this->assertFalse($handler->hasUndeliveredRecords());
    }

    /**
     * A record arriving behind the watermark (time 9 while watermark is 10)
     * goes to the dedicated late-record callback and is never delivered.
     */
    public function testLateRecordGoesToDedicatedCallback()
    {
        $test = new TestHandler();
        $late = [];
        $handler = new EventTimeOrderHandler(
            $test,
            allowedLateness: 2.0,
            lateRecordCallback: function (LogRecord $record) use (&$late): void {
                $late[] = $record->message;
            },
            bubble: false
        );

        $handler->handle($this->recordAt(10, 'ten'));
        $handler->handle($this->recordAt(12, 'twelve'));

        $handled = $handler->handle($this->recordAt(9, 'nine'));
        $this->assertTrue($handled, 'late record is handled (diverted), not rejected');
        $this->assertSame(['nine'], $late);

        $handler->close();
        $this->assertSame(['ten', 'twelve'], $this->messages($test), 'late record is never delivered downstream');
    }

    /**
     * The same UTC instant expressed with different timezone offsets is equal,
     * and equal-time records are delivered in enqueue order (stable).
     */
    public function testSameUtcInstantDifferentOffsetsAreEqualAndStable()
    {
        $test = new TestHandler();
        $late = [];
        $handler = new EventTimeOrderHandler(
            $test,
            allowedLateness: 2.0,
            lateRecordCallback: function (LogRecord $record) use (&$late): void {
                $late[] = $record->message;
            }
        );

        // open with 11 so the watermark (9) keeps the two t=10 records buffered together
        $handler->handle($this->recordAt(11, 'eleven'));

        $first = $this->getRecord(Level::Info, 'first', datetime: new \DateTimeImmutable('1970-01-01 02:00:10 +02:00'));
        $second = $this->getRecord(Level::Info, 'second', datetime: new \DateTimeImmutable('1970-01-01 00:00:10 +00:00'));
        $this->assertSame(
            (float) $first->datetime->format('U.u'),
            (float) $second->datetime->format('U.u'),
            'both datetimes denote the same UTC instant'
        );

        $handler->handle($first);
        $handler->handle($second);
        $this->assertSame([], $this->messages($test));
        $this->assertSame([], $late, 'equal-instants records are not late');

        // push the watermark to 10: both t=10 records flush in one pass
        $handler->handle($this->recordAt(12, 'twelve'));
        $this->assertSame(['first', 'second'], $this->messages($test), 'ties keep enqueue order');

        $handler->close();
        $this->assertSame(['first', 'second', 'eleven', 'twelve'], $this->messages($test));
    }

    /**
     * When the buffer is full, records are rejected (handle() returns false)
     * and the maximum accepted event time / watermark does not advance.
     */
    public function testBufferLimitRejectsWithoutAdvancingTime()
    {
        $test = new TestHandler();
        $handler = new EventTimeOrderHandler($test, allowedLateness: 100.0, bufferLimit: 1, bubble: false);

        $this->assertTrue($handler->handle($this->recordAt(10, 'ten')));
        $watermark = $handler->getWatermark();
        $this->assertSame(10.0 - 100.0, $watermark);

        $this->assertFalse($handler->handle($this->recordAt(50, 'fifty')), 'buffer full: record rejected');
        $this->assertSame(1, $handler->getRejectedCount());
        $this->assertSame($watermark, $handler->getWatermark(), 'rejection must not advance the watermark');

        $this->assertFalse($handler->handle($this->recordAt(12, 'twelve')));
        $this->assertSame(2, $handler->getRejectedCount());

        $handler->close();
        $this->assertSame(['ten'], $this->messages($test), 'only the accepted record was ever delivered');
    }

    /**
     * A failing wrapped handler turns the record into an explicit unknown
     * handoff; the record is not re-sent automatically and later flushes do
     * not re-deliver old records.
     */
    public function testUnderlyingFailureIsUnknownHandoffAndNotRedelivered()
    {
        $failing = new FailingHandler('boom');
        $unknown = [];
        $handler = new EventTimeOrderHandler(
            $failing,
            allowedLateness: 0.0,
            unknownHandoffCallback: function (LogRecord $record, \Throwable $error) use (&$unknown): void {
                $unknown[] = [$record->message, $error->getMessage()];
            }
        );

        $handler->handle($this->recordAt(10, 'boom'));
        $this->assertSame([], $failing->received);
        $this->assertSame([['boom', 'delivery failed']], $unknown);
        $this->assertCount(1, $handler->getUnknownHandoffs());
        $this->assertTrue($handler->hasUndeliveredRecords());

        // the next record is delivered normally and the failed one is NOT re-delivered
        $handler->handle($this->recordAt(11, 'ok'));
        $this->assertSame(['ok'], $failing->received);
        $this->assertCount(1, $handler->getUnknownHandoffs(), 'no automatic resend of the failed record');

        $handler->close();
        $this->assertSame(['ok'], $failing->received, 'close() does not re-deliver old failed records either');
        $this->assertSame(['boom'], array_map(
            static fn (LogRecord $record): string => $record->message,
            $handler->getUndeliveredRecords()
        ));
    }

    /**
     * close() with an unfinished queue reports the undelivered records instead
     * of pretending everything was delivered.
     */
    public function testCloseReportsUnfinishedQueue()
    {
        $failing = new FailingHandler(''); // empty needle: every record fails
        $handler = new EventTimeOrderHandler($failing, allowedLateness: 5.0);

        $handler->handle($this->recordAt(10, 'ten'));
        $this->assertSame(1, $handler->getBufferedCount(), 'record still buffered, watermark 5 not reached');

        $handler->close();

        $this->assertSame([], $failing->received);
        $this->assertTrue($handler->hasUndeliveredRecords(), 'close() must report the unfinished queue');
        $unknown = $handler->getUnknownHandoffs();
        $this->assertCount(1, $unknown);
        $this->assertSame('ten', $unknown[0]['record']->message);
        $this->assertInstanceOf(\RuntimeException::class, $unknown[0]['error']);

        // idempotent close: no second delivery attempt
        $handler->close();
        $this->assertCount(1, $handler->getUnknownHandoffs());
    }
}

/**
 * Test double: throws for records whose message contains the configured needle.
 */
class FailingHandler extends AbstractHandler
{
    /** @var list<string> */
    public array $received = [];

    public function __construct(private string $failOn)
    {
        parent::__construct();
    }

    public function handle(LogRecord $record): bool
    {
        if ('' === $this->failOn || str_contains($record->message, $this->failOn)) {
            throw new \RuntimeException('delivery failed');
        }

        $this->received[] = $record->message;

        return true;
    }
}
