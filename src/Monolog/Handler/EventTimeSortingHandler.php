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
use Monolog\ResettableInterface;
use Monolog\Formatter\FormatterInterface;

/**
 * Explicit event-time sorting handler.
 *
 * Records are buffered and sorted by their event time (UTC instant) and enqueue
 * order. A watermark is derived from the maximum accepted event time minus an
 * allowed lateness. Records whose event time is strictly below the watermark are
 * considered late and handed to an independent callback instead of being buffered.
 *
 * When the buffer reaches its limit, new records are rejected and handed to the
 * late callback; rejected records do not advance the watermark.
 *
 * Delivery to the wrapped handler happens in batches. If the wrapped handler
 * fails while processing a batch, the records of that batch are marked as an
 * unknown handoff: they are not resent automatically and are reported as
 * unfinished when the handler is closed.
 */
class EventTimeSortingHandler extends AbstractHandler implements ProcessableHandlerInterface, FormattableHandlerInterface
{
    use ProcessableHandlerTrait;

    protected HandlerInterface $handler;

    protected int $allowedLateness;

    protected int $bufferLimit;

    /** @var callable(LogRecord): void|null */
    protected $lateCallback;

    /** @var callable(LogRecord[], \Throwable|null): void|null */
    protected $unfinishedCallback;

    /**
     * Buffered entries, each holding the record and its enqueue sequence.
     *
     * @var array<int, array{record: LogRecord, seq: int}>
     */
    protected array $buffer = [];

    /**
     * Maximum event time (UTC timestamp in seconds) accepted so far.
     */
    protected ?int $maxEventTime = null;

    /**
     * Monotonically increasing enqueue sequence for stable ordering.
     */
    protected int $enqueueSeq = 0;

    /**
     * Records that were handed off but whose delivery status is unknown
     * because the wrapped handler failed while processing them.
     *
     * @var LogRecord[]
     */
    protected array $unknownRecords = [];

    protected ?\Throwable $lastFailure = null;

    protected bool $initialized = false;

    protected bool $closed = false;

    /**
     * @param HandlerInterface                               $handler            The handler that will receive the sorted batches.
     * @param int                                            $allowedLateness    Maximum allowed out-of-orderness in seconds; the watermark is maxEventTime - allowedLateness.
     * @param int                                            $bufferLimit        Maximum number of records to buffer (0 = unlimited).
     * @param callable(LogRecord):void|null                  $lateCallback       Invoked for late records and records rejected because the buffer is full.
     * @param callable(LogRecord[],\Throwable|null):void|null $unfinishedCallback Invoked on close with the records whose handoff status is unknown.
     */
    public function __construct(
        HandlerInterface $handler,
        int $allowedLateness = 0,
        int $bufferLimit = 0,
        ?callable $lateCallback = null,
        ?callable $unfinishedCallback = null,
        int|string|Level $level = Level::Debug,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
        $this->handler = $handler;
        $this->allowedLateness = $allowedLateness;
        $this->bufferLimit = $bufferLimit;
        $this->lateCallback = $lateCallback;
        $this->unfinishedCallback = $unfinishedCallback;
    }

    /**
     * @inheritDoc
     */
    public function handle(LogRecord $record): bool
    {
        if ($record->level->isLowerThan($this->level)) {
            return false;
        }

        if (!$this->initialized) {
            // __destructor() doesn't get called on Fatal errors
            register_shutdown_function([$this, 'close']);
            $this->initialized = true;
        }

        if (\count($this->processors) > 0) {
            $record = $this->processRecord($record);
        }

        $eventTime = $this->getEventTime($record);
        $watermark = $this->getWatermark();

        // Buffer full: reject the record. Rejected records do not advance the
        // watermark and are handed to the late callback.
        if ($this->bufferLimit > 0 && \count($this->buffer) >= $this->bufferLimit) {
            $this->notifyLate($record);

            return false === $this->bubble;
        }

        // Late record: event time is strictly below the watermark. It is not
        // buffered and does not advance the watermark.
        if ($this->maxEventTime !== null && $eventTime < $watermark) {
            $this->notifyLate($record);

            return false === $this->bubble;
        }

        // Accept the record into the buffer.
        $this->buffer[] = ['record' => $record, 'seq' => $this->enqueueSeq++];
        if ($this->maxEventTime === null || $eventTime > $this->maxEventTime) {
            $this->maxEventTime = $eventTime;
        }

        $this->deliverDue();

        return false === $this->bubble;
    }

    /**
     * Returns the event time of a record as a UTC timestamp.
     *
     * Two records representing the same UTC instant but carrying different
     * offsets compare equal here, because getTimestamp() always returns the
     * UTC-based Unix timestamp.
     */
    protected function getEventTime(LogRecord $record): int
    {
        return $record->datetime->getTimestamp();
    }

    /**
     * Delivers all buffered records whose event time is at or below the current
     * watermark, sorted by (event time, enqueue sequence).
     */
    protected function deliverDue(): void
    {
        $watermark = $this->getWatermark();
        $due = [];
        $remaining = [];

        foreach ($this->buffer as $entry) {
            if ($this->getEventTime($entry['record']) <= $watermark) {
                $due[] = $entry;
            } else {
                $remaining[] = $entry;
            }
        }

        if (\count($due) === 0) {
            return;
        }

        $this->sortEntries($due);

        $records = array_column($due, 'record');
        $this->buffer = $remaining;

        $this->handoff($records);
    }

    /**
     * Hands a batch of records to the wrapped handler.
     *
     * If the wrapped handler throws, the records of that batch are marked as
     * an unknown handoff: they are not resent automatically.
     *
     * @param LogRecord[] $records
     */
    protected function handoff(array $records): void
    {
        try {
            $this->handler->handleBatch($records);
        } catch (\Throwable $e) {
            // Unknown handoff: do not resend, remember the records for the
            // unfinished queue report.
            $this->lastFailure = $e;
            foreach ($records as $record) {
                $this->unknownRecords[] = $record;
            }
        }
    }

    protected function notifyLate(LogRecord $record): void
    {
        if ($this->lateCallback !== null) {
            ($this->lateCallback)($record);
        }
    }

    /**
     * Sorts entries by (event time, enqueue sequence) for stable ordering.
     *
     * @param array<int, array{record: LogRecord, seq: int}> $entries
     */
    protected function sortEntries(array &$entries): void
    {
        usort($entries, function (array $a, array $b): int {
            return $this->getEventTime($a['record']) <=> $this->getEventTime($b['record'])
                ?: $a['seq'] <=> $b['seq'];
        });
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        // Flush all remaining buffered records, sorted by (event time, seq).
        if (\count($this->buffer) > 0) {
            $entries = $this->buffer;
            $this->sortEntries($entries);
            $records = array_column($entries, 'record');
            $this->buffer = [];
            $this->handoff($records);
        }

        $unfinished = $this->getUnfinishedRecords();
        if (\count($unfinished) > 0 && $this->unfinishedCallback !== null) {
            ($this->unfinishedCallback)($unfinished, $this->lastFailure);
        }

        $this->handler->close();
    }

    /**
     * Clears the buffer and any recorded failure state without flushing.
     */
    public function clear(): void
    {
        $this->buffer = [];
        $this->unknownRecords = [];
        $this->maxEventTime = null;
        $this->enqueueSeq = 0;
        $this->lastFailure = null;
    }

    /**
     * Returns the records whose handoff status is unknown because the wrapped
     * handler failed while processing them.
     *
     * @return LogRecord[]
     */
    public function getUnfinishedRecords(): array
    {
        return $this->unknownRecords;
    }

    public function getLastFailure(): ?\Throwable
    {
        return $this->lastFailure;
    }

    public function getMaxEventTime(): ?int
    {
        return $this->maxEventTime;
    }

    public function getWatermark(): int
    {
        return (int) ($this->maxEventTime ?? 0) - $this->allowedLateness;
    }

    public function getBufferCount(): int
    {
        return \count($this->buffer);
    }

    /**
     * @inheritDoc
     */
    public function reset(): void
    {
        $this->close();
        $this->clear();
        $this->closed = false;
        parent::reset();
        $this->resetProcessors();

        if ($this->handler instanceof ResettableInterface) {
            $this->handler->reset();
        }
    }

    public function __destruct()
    {
        // Suppress the parent behavior since register_shutdown_function()
        // already calls close(), and the reference held there prevents GC.
    }

    /**
     * @inheritDoc
     */
    public function setFormatter(FormatterInterface $formatter): HandlerInterface
    {
        if ($this->handler instanceof FormattableHandlerInterface) {
            $this->handler->setFormatter($formatter);

            return $this;
        }

        throw new \UnexpectedValueException('The nested handler of type '.\get_class($this->handler).' does not support formatters.');
    }

    /**
     * @inheritDoc
     */
    public function getFormatter(): FormatterInterface
    {
        if ($this->handler instanceof FormattableHandlerInterface) {
            return $this->handler->getFormatter();
        }

        throw new \UnexpectedValueException('The nested handler of type '.\get_class($this->handler).' does not support formatters.');
    }

    public function setHandler(HandlerInterface $handler): self
    {
        $this->handler = $handler;

        return $this;
    }
}
