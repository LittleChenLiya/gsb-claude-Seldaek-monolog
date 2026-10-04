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
use Monolog\ResettableInterface;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Buffers records independently for each wrapped handler and flushes them
 * as per-target batches.
 *
 * Each target handler accumulates its own batch of records. When flush() is
 * called every target is committed separately: if a target fails while
 * sending a batch, the failing batch (and any batches that had not been sent
 * yet) are retained untouched for that target, the error is recorded, and the
 * next flush() call only retries the failed targets. Records added after a
 * failure are buffered as a new batch and are sent after the failed batch has
 * been retried successfully.
 *
 * This guarantees that:
 *  - a failing target never causes already successful targets to be sent again;
 *  - a failing (or misbehaving) handler can never corrupt the buffered records,
 *    as records are cloned per target when buffered and again when delivered;
 *  - a per-target buffer limit prevents unbounded memory growth, discarding
 *    the oldest records and reporting the discarded count through a callback.
 *
 * Usage example:
 *
 * <code>
 *   $logger = new Logger('application');
 *   $handler = new BufferedGroupHandler(
 *       [$slackHandler, $socketHandler],
 *       bufferLimit: 100,
 *       onDiscard: function (int $count, HandlerInterface $handler, int $index) use ($logger): void {
 *           $logger->warning('Discarded {count} records for target {target}', [
 *               'count' => $count,
 *               'target' => $handler::class,
 *           ]);
 *       },
 *   );
 *
 *   $logger->pushHandler($handler);
 *
 *   // ... log records ...
 *
 *   // commits one batch per target, failed targets being retried on later calls
 *   $handler->flush();
 * </code>
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class BufferedGroupHandler extends AbstractHandler implements ProcessableHandlerInterface, FormattableHandlerInterface, ResettableInterface
{
    use ProcessableHandlerTrait;

    /** @var HandlerInterface[] */
    protected array $handlers;

    protected int $bufferLimit;

    /** @var callable|null Signature: function(int $count, HandlerInterface $handler, int $index): void */
    protected $onDiscard;

    /**
     * Records accumulated for each target since its last flush.
     *
     * @var array<int, list<LogRecord>>
     */
    protected array $buffers = [];

    /**
     * Sealed batches awaiting delivery for each target.
     *
     * @var array<int, list<list<LogRecord>>>
     */
    protected array $queues = [];

    /**
     * The last error recorded for each failed target, keyed by handler index.
     *
     * @var array<int, \Throwable>
     */
    protected array $errors = [];

    /**
     * Cumulated number of records discarded for each target because of the buffer limit.
     *
     * @var array<int, int>
     */
    protected array $discardCounts = [];

    protected bool $initialized = false;

    /**
     * @param HandlerInterface[] $handlers    The handlers that will each receive their own buffered batches
     * @param int                $bufferLimit Maximum number of records buffered per target; 0 means unlimited
     * @param int|string|Level   $level       The minimum logging level at which this handler will be triggered
     * @param bool               $bubble     Whether the messages that are handled can bubble up the stack or not
     * @param callable|null      $onDiscard   Callback invoked when records are discarded because of the buffer limit:
     *                                        function(int $count, HandlerInterface $handler, int $index): void
     */
    public function __construct(array $handlers, int $bufferLimit = 0, int|string|Level $level = Level::Debug, bool $bubble = true, ?callable $onDiscard = null)
    {
        foreach ($handlers as $handler) {
            if (!$handler instanceof HandlerInterface) {
                throw new \InvalidArgumentException('The first argument of the BufferedGroupHandler must be an array of HandlerInterface instances.');
            }
        }

        parent::__construct($level, $bubble);
        $this->handlers = $handlers;
        $this->bufferLimit = $bufferLimit;
        $this->onDiscard = $onDiscard;

        foreach (\array_keys($handlers) as $index) {
            $this->buffers[$index] = [];
            $this->queues[$index] = [];
            $this->discardCounts[$index] = 0;
        }
    }

    /**
     * @inheritDoc
     */
    public function isHandling(LogRecord $record): bool
    {
        foreach ($this->handlers as $handler) {
            if ($handler->isHandling($record)) {
                return true;
            }
        }

        return false;
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

        foreach ($this->handlers as $index => $handler) {
            $this->bufferRecord($index, $handler, clone $record);
        }

        return false === $this->bubble;
    }

    /**
     * @inheritDoc
     */
    public function handleBatch(array $records): void
    {
        if (!$this->initialized) {
            register_shutdown_function([$this, 'close']);
            $this->initialized = true;
        }

        if (\count($this->processors) > 0) {
            $processed = [];
            foreach ($records as $record) {
                $processed[] = $this->processRecord($record);
            }
            $records = $processed;
        }

        foreach ($this->handlers as $index => $handler) {
            foreach ($records as $record) {
                if ($record->level->isLowerThan($this->level)) {
                    continue;
                }
                $this->bufferRecord($index, $handler, clone $record);
            }
        }
    }

    /**
     * Buffers one record for one target, applying the per-target buffer limit.
     */
    protected function bufferRecord(int $index, HandlerInterface $handler, LogRecord $record): void
    {
        if ($this->bufferLimit > 0 && \count($this->buffers[$index]) >= $this->bufferLimit) {
            $this->discard($index, $handler, 1);
            array_shift($this->buffers[$index]);
        }

        $this->buffers[$index][] = $record;
    }

    /**
     * Discards $count oldest records from the target buffer and reports it.
     */
    protected function discard(int $index, HandlerInterface $handler, int $count): void
    {
        $this->discardCounts[$index] += $count;

        if ($this->onDiscard !== null) {
            ($this->onDiscard)($count, $handler, $index);
        }
    }

    /**
     * Flushes every target independently.
     *
     * Each target's currently open buffer is sealed as a batch and all pending
     * batches are sent in order. If a target fails, its error is recorded and
     * its pending batches are retained for the next flush() call; other
     * targets are unaffected.
     *
     * While any target is in a failure state, healthy targets are not sent
     * their new records yet: the next flush() call only retries the failed
     * targets (which also send the records that accumulated meanwhile as their
     * next batch). Once every failure has been resolved, healthy targets send
     * their held records on the following flush() call.
     *
     * @param bool $force When true, every target is flushed regardless of the
     *                    failure state and failed targets also seal their open
     *                    buffer (used by close()/reset()).
     */
    public function flush(bool $force = false): void
    {
        $hasFailures = $this->errors !== [];

        foreach ($this->handlers as $index => $handler) {
            if ($force || !$hasFailures || isset($this->errors[$index])) {
                $this->flushTarget($index, $handler, $force);
            }
        }
    }

    /**
     * Flushes a single target, retaining batches and the error on failure.
     *
     * A healthy target seals its currently open buffer as a batch and sends
     * every pending batch in order. A target that is currently in a failure
     * state only retries its retained batch(es): its open buffer is left
     * untouched, so records accumulated during the failure window form the
     * next batch and never alter the batch that is being retried.
     *
     * When $sealOpenBuffer is true (close()/reset()), the open buffer is
     * sealed even for a failed target, appended after the retained batch(es)
     * so it is sent once the retry succeeds.
     */
    protected function flushTarget(int $index, HandlerInterface $handler, bool $sealOpenBuffer = false): void
    {
        if (($sealOpenBuffer || !isset($this->errors[$index])) && $this->buffers[$index] !== []) {
            $this->queues[$index][] = $this->buffers[$index];
            $this->buffers[$index] = [];
        }

        while ($this->queues[$index] !== []) {
            $batch = $this->queues[$index][0];

            try {
                // Clone again so a misbehaving handler can never mutate the
                // buffered batch, which must be re-sent identically on retry.
                $handler->handleBatch(\array_map(static fn (LogRecord $record): LogRecord => clone $record, $batch));
            } catch (\Throwable $e) {
                $this->errors[$index] = $e;

                return;
            }

            array_shift($this->queues[$index]);
        }

        unset($this->errors[$index]);
    }

    /**
     * Returns the last error recorded for each failed target, keyed by handler index.
     *
     * @return array<int, \Throwable>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasFailures(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Returns the cumulated number of records discarded for each target because of the buffer limit.
     *
     * @return array<int, int>
     */
    public function getDiscardCounts(): array
    {
        return $this->discardCounts;
    }

    public function getDiscardCount(int $index): int
    {
        return $this->discardCounts[$index] ?? 0;
    }

    /**
     * Clears every buffer without flushing any records to the wrapped handlers.
     */
    public function clear(): void
    {
        foreach (\array_keys($this->handlers) as $index) {
            $this->buffers[$index] = [];
            $this->queues[$index] = [];
        }
        $this->errors = [];
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        $this->flush(true);

        foreach ($this->handlers as $handler) {
            $handler->close();
        }
    }

    public function __destruct()
    {
        // suppress the parent behavior since we already have register_shutdown_function()
        // to call close(), and the reference contained there will prevent this from being
        // GC'd until the end of the request
    }

    /**
     * @inheritDoc
     */
    public function reset(): void
    {
        $this->flush(true);

        parent::reset();

        $this->resetProcessors();

        foreach ($this->handlers as $handler) {
            if ($handler instanceof ResettableInterface) {
                $handler->reset();
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function setFormatter(FormatterInterface $formatter): HandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter($formatter);
            }
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getFormatter(): FormatterInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                return $handler->getFormatter();
            }
        }

        throw new \UnexpectedValueException('None of the nested handlers of type ' . \implode(', ', \array_map(static fn (HandlerInterface $handler): string => \get_class($handler), $this->handlers)) . ' supports formatters.');
    }
}
