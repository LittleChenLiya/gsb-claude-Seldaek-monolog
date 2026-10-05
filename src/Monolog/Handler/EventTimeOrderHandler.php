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

/**
 * Explicit event-time ordering handler.
 *
 * Records are buffered and delivered to the wrapped handler ordered by their
 * event time (the record datetime, compared as a UTC instant so that the same
 * instant written with different timezone offsets is considered equal), with
 * ties broken by enqueue order (stable ordering).
 *
 * A watermark is maintained as "maximum accepted event time minus the allowed
 * lateness (in seconds)". Buffered records whose event time is at or below the
 * watermark are delivered. Records arriving with an event time strictly below
 * the current watermark are late: they are not buffered nor delivered to the
 * wrapped handler but handed to a dedicated late-record callback instead.
 *
 * When the buffer is full, incoming records are rejected (handle() returns
 * false) without advancing the maximum accepted event time, so a rejected
 * record can never move the watermark.
 *
 * Delivery is at-most-once: if the wrapped handler throws while a record is
 * being delivered, that record is handed over to the unknown-handoff callback
 * (its delivery state is explicitly unknown) and it is never re-sent
 * automatically.
 *
 * close() flushes whatever is still buffered, in (event time, enqueue order)
 * sequence. Records that could not be delivered (unknown handoffs) are kept
 * and reported via getUndeliveredRecords()/hasUndeliveredRecords() so that
 * close() never pretends everything was delivered when it was not.
 */
class EventTimeOrderHandler extends AbstractHandler implements ProcessableHandlerInterface, ResettableInterface
{
    use ProcessableHandlerTrait;

    protected HandlerInterface $handler;

    /** Allowed lateness in seconds (may be fractional) */
    protected float $allowedLateness;

    /** Maximum number of buffered records, 0 means unlimited */
    protected int $bufferLimit;

    /** @var null|callable(LogRecord): void */
    protected $lateRecordCallback;

    /** @var null|callable(LogRecord, \Throwable): void */
    protected $unknownHandoffCallback;

    /** @var null|callable(LogRecord): float */
    protected $eventTimeExtractor;

    /** @var list<array{time: float, seq: int, record: LogRecord}> */
    protected array $buffer = [];

    protected ?float $maxEventTime = null;

    protected int $sequence = 0;

    protected int $rejectedCount = 0;

    /** @var list<array{record: LogRecord, error: \Throwable}> */
    protected array $unknownHandoffs = [];

    protected bool $closed = false;

    /**
     * @param HandlerInterface                           $handler                 The wrapped handler receiving records in event-time order
     * @param float                                      $allowedLateness         Allowed lateness in seconds; watermark = max accepted event time - allowedLateness
     * @param int                                        $bufferLimit             Maximum buffered records (0 = unlimited); excess records are rejected without advancing the watermark
     * @param null|callable(LogRecord): void             $lateRecordCallback      Invoked with records arriving behind the watermark
     * @param null|callable(LogRecord, \Throwable): void $unknownHandoffCallback  Invoked when the wrapped handler fails; delivery state of the record is then unknown
     * @param null|callable(LogRecord): float            $eventTimeExtractor      Custom explicit event-time extractor (seconds, UTC); defaults to the record datetime
     * @param int|string|Level                           $level                   Minimum level handled
     * @param bool                                       $bubble                  Whether records bubble up the handler stack
     *
     * @phpstan-param value-of<\Monolog\Level::VALUES>|value-of<\Monolog\Level::NAMES>|\Monolog\Level|\Psr\Log\LogLevel::* $level
     */
    public function __construct(
        HandlerInterface $handler,
        float $allowedLateness = 0.0,
        int $bufferLimit = 0,
        ?callable $lateRecordCallback = null,
        ?callable $unknownHandoffCallback = null,
        ?callable $eventTimeExtractor = null,
        int|string|Level $level = Level::Debug,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
        $this->handler = $handler;
        $this->allowedLateness = $allowedLateness;
        $this->bufferLimit = $bufferLimit;
        $this->lateRecordCallback = $lateRecordCallback;
        $this->unknownHandoffCallback = $unknownHandoffCallback;
        $this->eventTimeExtractor = $eventTimeExtractor;
    }

    /**
     * @inheritDoc
     */
    public function handle(LogRecord $record): bool
    {
        if ($record->level->isLowerThan($this->level)) {
            return false;
        }

        if ($this->closed) {
            // after close() no further records are accepted; nothing is silently dropped
            return false;
        }

        $time = $this->eventTime($record);
        $watermark = $this->getWatermark();

        if (null !== $watermark && $time < $watermark) {
            // late record: diverted to the dedicated callback, never delivered downstream
            if (null !== $this->lateRecordCallback) {
                ($this->lateRecordCallback)($record);
            }

            return false === $this->bubble;
        }

        if ($this->bufferLimit > 0 && \count($this->buffer) >= $this->bufferLimit) {
            // rejected: the record is not accepted and the max event time is NOT advanced
            $this->rejectedCount++;

            return false;
        }

        if (\count($this->processors) > 0) {
            $record = $this->processRecord($record);
        }

        $this->maxEventTime = null === $this->maxEventTime ? $time : max($this->maxEventTime, $time);
        $this->buffer[] = ['time' => $time, 'seq' => $this->sequence++, 'record' => $record];

        $this->flushReady();

        return false === $this->bubble;
    }

    /**
     * Current watermark (max accepted event time minus allowed lateness), or null before any record was accepted.
     */
    public function getWatermark(): ?float
    {
        if (null === $this->maxEventTime) {
            return null;
        }

        return $this->maxEventTime - $this->allowedLateness;
    }

    /**
     * Number of records rejected because the buffer limit was reached.
     */
    public function getRejectedCount(): int
    {
        return $this->rejectedCount;
    }

    /**
     * Number of records currently buffered (not yet delivered).
     */
    public function getBufferedCount(): int
    {
        return \count($this->buffer);
    }

    /**
     * Records whose delivery to the wrapped handler failed and whose final
     * delivery state is therefore explicitly unknown. They are kept here so
     * that close() can report an unfinished queue instead of pretending that
     * everything was delivered.
     *
     * @return list<array{record: LogRecord, error: \Throwable}>
     */
    public function getUnknownHandoffs(): array
    {
        return $this->unknownHandoffs;
    }

    /**
     * @return list<LogRecord>
     */
    public function getUndeliveredRecords(): array
    {
        return array_map(static fn (array $entry): LogRecord => $entry['record'], $this->unknownHandoffs);
    }

    public function hasUndeliveredRecords(): bool
    {
        return \count($this->unknownHandoffs) > 0 || \count($this->buffer) > 0;
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        // flush everything still buffered, in (event time, enqueue order) sequence;
        // failures are tracked in $this->unknownHandoffs and stay inspectable
        // via getUnknownHandoffs()/getUndeliveredRecords() - they are not
        // silently swallowed nor automatically re-sent.
        $this->flushBuffer(null);
        $this->closed = true;

        $this->handler->close();
    }

    public function reset(): void
    {
        parent::reset();
        $this->resetProcessors();
        $this->buffer = [];
        $this->maxEventTime = null;
        $this->sequence = 0;
        $this->rejectedCount = 0;
        $this->unknownHandoffs = [];
        $this->closed = false;
    }

    /**
     * Explicit event time of a record as a UTC instant in seconds (with
     * microseconds). Two datetimes denoting the same UTC instant with
     * different timezone offsets map to the same value.
     */
    protected function eventTime(LogRecord $record): float
    {
        if (null !== $this->eventTimeExtractor) {
            return ($this->eventTimeExtractor)($record);
        }

        return (float) $record->datetime->format('U.u');
    }

    /**
     * Delivers all buffered records at or below the current watermark.
     */
    protected function flushReady(): void
    {
        $this->flushBuffer($this->getWatermark());
    }

    /**
     * Delivers buffered records with event time <= $upTo (all of them when
     * $upTo is null), ordered by (event time, enqueue sequence).
     */
    protected function flushBuffer(?float $upTo): void
    {
        if (\count($this->buffer) === 0) {
            return;
        }

        usort(
            $this->buffer,
            static fn (array $a, array $b): int => $a['time'] <=> $b['time'] ?: $a['seq'] <=> $b['seq']
        );

        $ready = [];
        $remaining = [];
        foreach ($this->buffer as $entry) {
            if (null === $upTo || $entry['time'] <= $upTo) {
                $ready[] = $entry;
            } else {
                $remaining[] = $entry;
            }
        }
        $this->buffer = $remaining;

        foreach ($ready as $entry) {
            $this->deliver($entry['record']);
        }
    }

    /**
     * At-most-once delivery: a failing wrapped handler marks the record as an
     * unknown handoff; it is removed from the queue and never re-sent.
     */
    protected function deliver(LogRecord $record): void
    {
        try {
            $this->handler->handle($record);
        } catch (\Throwable $e) {
            $this->unknownHandoffs[] = ['record' => $record, 'error' => $e];
            if (null !== $this->unknownHandoffCallback) {
                ($this->unknownHandoffCallback)($record, $e);
            }
        }
    }
}
