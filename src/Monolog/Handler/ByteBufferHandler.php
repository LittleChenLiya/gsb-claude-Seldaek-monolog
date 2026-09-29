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

/**
 * Buffers records up to a serialized byte size limit and then passes them as batch.
 *
 * This works like BufferHandler except that the buffer capacity is expressed in
 * bytes instead of record count: every record is normalized exactly once when it
 * enters the buffer and its serialized UTF-8 byte size is remembered, so the
 * limit is enforced before a record is appended and the buffer never holds more
 * than the configured amount of bytes.
 *
 * A single record whose serialized size exceeds the byte limit can never be
 * buffered: pending records are flushed first and the oversized record is passed
 * through immediately as a batch of its own.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ByteBufferHandler extends BufferHandler
{
    protected int $byteLimit;

    protected int $bufferBytes = 0;

    /** @var int[] serialized byte size of each buffered record, aligned with $this->buffer */
    protected array $bufferRecordSizes = [];

    protected NormalizerFormatter $sizeFormatter;

    /**
     * @param HandlerInterface $handler         Handler.
     * @param int              $byteLimit       Maximum cumulative serialized size in bytes of the buffered records, 0 means unlimited.
     * @param bool             $flushOnOverflow If true, the buffer is flushed when appending a record would exceed $byteLimit, by default the new record is rejected instead
     */
    public function __construct(HandlerInterface $handler, int $byteLimit = 0, int|string|Level $level = Level::Debug, bool $bubble = true, bool $flushOnOverflow = false)
    {
        parent::__construct($handler, 0, $level, $bubble, $flushOnOverflow);
        $this->byteLimit = $byteLimit;
        $this->sizeFormatter = new NormalizerFormatter();
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

        $recordSize = $this->computeRecordSize($record);

        if ($this->byteLimit > 0 && $recordSize > $this->byteLimit) {
            // a single record larger than the limit can never be buffered:
            // flush pending records and pass it through immediately on its own
            $this->flush();
            $this->handler->handleBatch([$record]);

            return false === $this->bubble;
        }

        if ($this->byteLimit > 0 && $this->bufferBytes + $recordSize > $this->byteLimit) {
            if ($this->flushOnOverflow) {
                $this->flush();
            } else {
                // reject the new record so the buffer stays within the byte limit
                return false === $this->bubble;
            }
        }

        $this->buffer[] = $record;
        $this->bufferRecordSizes[] = $recordSize;
        $this->bufferSize++;
        $this->bufferBytes += $recordSize;

        return false === $this->bubble;
    }

    public function flush(): void
    {
        if ($this->bufferSize === 0) {
            return;
        }

        $batch = $this->buffer;
        $batchSizes = $this->bufferRecordSizes;
        $batchBytes = $this->bufferBytes;
        // reset before delivering so that records logged re-entrantly by the
        // wrapped handler start a fresh batch instead of being wiped out
        $this->clear();

        try {
            $this->handler->handleBatch($batch);
        } catch (\Throwable $exception) {
            // keep the failed batch ahead of any re-entrantly buffered records
            // so it can be retried as-is on the next flush
            $this->buffer = array_merge($batch, $this->buffer);
            $this->bufferRecordSizes = array_merge($batchSizes, $this->bufferRecordSizes);
            $this->bufferSize = \count($this->buffer);
            $this->bufferBytes += $batchBytes;

            throw $exception;
        }
    }

    /**
     * Clears the buffer without flushing any messages down to the wrapped handler.
     */
    public function clear(): void
    {
        parent::clear();
        $this->bufferBytes = 0;
        $this->bufferRecordSizes = [];
    }

    /**
     * Returns the serialized size in bytes of the currently buffered records.
     */
    public function getBufferedSize(): int
    {
        return $this->bufferBytes;
    }

    /**
     * Computes the serialized UTF-8 byte size of a record.
     *
     * The record is normalized once when it enters the buffer and the resulting
     * size is remembered alongside it, so records are never normalized again
     * while they sit in the buffer or when they are flushed.
     */
    protected function computeRecordSize(LogRecord $record): int
    {
        return \strlen(Utils::jsonEncode($this->sizeFormatter->format($record), null, true));
    }
}
