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

/**
 * Buffers records like BufferHandler, but caps the buffer by the cumulated
 * serialized size of the records (in bytes) instead of by record count.
 *
 * Every record is normalized exactly once when it enters the buffer and its
 * serialized byte size is remembered, so the buffer never exceeds the
 * configured capacity while records pile up.
 *
 * When adding a record would exceed the capacity, either the current buffer
 * is flushed first (flushOnOverflow = true) or the incoming record is
 * rejected (flushOnOverflow = false). A single record larger than the whole
 * capacity is delivered directly as its own batch when flushing on overflow,
 * and rejected otherwise.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ByteBufferHandler extends BufferHandler
{
    protected int $byteLimit;

    protected int $bufferByteSize = 0;

    /** @var int[] */
    protected array $bufferBytes = [];

    private ?NormalizerFormatter $normalizer = null;

    /**
     * @param HandlerInterface $handler         Handler.
     * @param int              $byteLimit       How many bytes the buffered records may occupy at most (based on their serialized form), 0 means unlimited.
     * @param bool             $flushOnOverflow If true, the buffer is flushed when the capacity is reached, by default incoming records are rejected
     */
    public function __construct(HandlerInterface $handler, int $byteLimit = 0, int|string|Level $level = Level::Debug, bool $bubble = true, bool $flushOnOverflow = false)
    {
        parent::__construct($handler, 0, $level, $bubble, $flushOnOverflow);
        $this->byteLimit = $byteLimit;
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

        $byteSize = $this->getRecordByteSize($record);

        if ($this->byteLimit > 0 && $this->bufferByteSize + $byteSize > $this->byteLimit) {
            if (!$this->flushOnOverflow) {
                // reject the incoming record, the buffer is left untouched
                return false === $this->bubble;
            }

            $this->flush();

            if ($byteSize > $this->byteLimit) {
                // a single record bigger than the whole capacity would sit
                // above the limit indefinitely, deliver it directly instead
                $this->handler->handleBatch([$record]);

                return false === $this->bubble;
            }
        }

        $this->buffer[] = $record;
        $this->bufferBytes[] = $byteSize;
        $this->bufferSize++;
        $this->bufferByteSize += $byteSize;

        return false === $this->bubble;
    }

    public function flush(): void
    {
        if ($this->bufferSize === 0) {
            return;
        }

        $buffer = $this->buffer;
        $bufferBytes = $this->bufferBytes;
        $bufferSize = $this->bufferSize;
        $bufferByteSize = $this->bufferByteSize;

        // clear before handing the batch over so that records logged
        // recursively while flushing are buffered separately and not lost
        $this->clear();

        try {
            $this->handler->handleBatch($buffer);
        } catch (\Throwable $e) {
            // restore the failed batch ahead of any recursively buffered
            // records so it can be retried without losing its order
            $this->buffer = array_merge($buffer, $this->buffer);
            $this->bufferBytes = array_merge($bufferBytes, $this->bufferBytes);
            $this->bufferSize += $bufferSize;
            $this->bufferByteSize += $bufferByteSize;

            throw $e;
        }
    }

    /**
     * Clears the buffer without flushing any messages down to the wrapped handler.
     */
    public function clear(): void
    {
        parent::clear();
        $this->bufferBytes = [];
        $this->bufferByteSize = 0;
    }

    /**
     * Returns the serialized size in bytes of the records currently buffered.
     */
    public function getBufferByteSize(): int
    {
        return $this->bufferByteSize;
    }

    /**
     * Returns the serialized size of a record in bytes.
     *
     * The record is normalized once (when it enters the buffer) and encoded
     * as UTF-8 JSON, so multi-byte characters are accounted for by their
     * actual byte length and not by character count.
     */
    protected function getRecordByteSize(LogRecord $record): int
    {
        $this->normalizer ??= new NormalizerFormatter();

        $serialized = json_encode(
            $this->normalizer->format($record),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return \strlen(false === $serialized ? '' : $serialized);
    }
}
