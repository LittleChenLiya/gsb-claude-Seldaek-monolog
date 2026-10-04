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
use Psr\Log\LogLevel;

/**
 * Buffers records independently for multiple targets and flushes each
 * target as a separate batch.
 *
 * Unlike GroupHandler (which forwards every record immediately) or
 * BufferHandler (which buffers for a single nested handler), this handler
 * keeps one buffer per target and commits each target separately on
 * flush():
 *
 * - A target whose batch was already delivered successfully is never
 *   sent the same records again, even if another target fails.
 * - When a target fails, its batch is snapshotted and kept as a pending
 *   batch together with the thrown error. The next flush() retries only
 *   the pending batch of the failed target(s); healthy targets keep
 *   flushing their own buffers normally.
 * - Records arriving while a target has a pending failed batch are
 *   accumulated into that target's next batch. The pending batch is
 *   never modified, so a retry always sends exactly the records that
 *   failed. The accumulated records are only sent (as a separate batch)
 *   after the pending batch has been delivered successfully, preserving
 *   per-target ordering.
 * - Immutability is enforced by the handler, not by the caller: records
 *   are cloned when buffered and again when dispatched, so neither the
 *   caller nor a misbehaving nested handler can mutate a batch that has
 *   been or will be (re-)sent.
 *
 * Each target buffer may be capped with $bufferLimit. When the cap is
 * reached the oldest record of that target is dropped, a per-target
 * discarded counter is incremented and the $overflowCallback (if any) is
 * invoked so the drop is reported explicitly instead of happening
 * silently.
 */
class MultiTargetBufferHandler extends AbstractHandler implements ProcessableHandlerInterface, FormattableHandlerInterface
{
    use ProcessableHandlerTrait;

    /**
     * @var array<array-key, array{handler: HandlerInterface, buffer: LogRecord[], pending: LogRecord[]|null, error: \Throwable|null, discarded: int}>
     */
    protected array $targets = [];

    protected int $bufferLimit;

    /** @var callable|null */
    protected $overflowCallback;

    protected bool $initialized = false;

    /**
     * @param array<array-key, HandlerInterface> $handlers         Map of target name => handler. Each target gets its own independent buffer.
     * @param int                                $bufferLimit      How many entries should be buffered at most per target (0 = unlimited).
     *                                                             Beyond that the oldest items of that target are discarded and reported.
     * @param int|string|Level                   $level            The minimum logging level at which this handler will be triggered
     * @param bool                               $bubble           Whether the messages that are handled can bubble up the stack or not
     * @param callable|null                      $overflowCallback Called when a record is discarded because a target buffer is full.
     *                                                             Signature: function (int|string $target, LogRecord $dropped, int $discardedCount): void
     *                                                             where $discardedCount is the running total of discarded records for that target.
     *
     * @phpstan-param value-of<Level::VALUES>|value-of<Level::NAMES>|Level|LogLevel::* $level
     *
     * @throws \InvalidArgumentException if an unsupported handler is set
     */
    public function __construct(array $handlers, int $bufferLimit = 0, int|string|Level $level = Level::Debug, bool $bubble = true, ?callable $overflowCallback = null)
    {
        parent::__construct($level, $bubble);

        if (\count($handlers) === 0) {
            throw new \InvalidArgumentException('The first argument of the MultiTargetBufferHandler must be a non-empty array of HandlerInterface instances.');
        }

        foreach ($handlers as $target => $handler) {
            if (!$handler instanceof HandlerInterface) {
                throw new \InvalidArgumentException('The first argument of the MultiTargetBufferHandler must be an array of HandlerInterface instances.');
            }

            $this->targets[$target] = [
                'handler' => $handler,
                'buffer' => [],
                'pending' => null,
                'error' => null,
                'discarded' => 0,
            ];
        }

        $this->bufferLimit = $bufferLimit;
        $this->overflowCallback = $overflowCallback;
    }

    /**
     * @inheritDoc
     */
    public function isHandling(LogRecord $record): bool
    {
        if ($record->level->isLowerThan($this->level)) {
            return false;
        }

        foreach ($this->targets as $target) {
            if ($target['handler']->isHandling($record)) {
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
            // __destructor() does not get called on Fatal errors
            register_shutdown_function([$this, 'close']);
            $this->initialized = true;
        }

        if (\count($this->processors) > 0) {
            $record = $this->processRecord($record);
        }

        foreach ($this->targets as $key => &$target) {
            // Clone per target so neither the caller nor the other targets
            // can mutate the record this target has buffered.
            $buffered = clone $record;

            if ($this->bufferLimit > 0 && \count($target['buffer']) === $this->bufferLimit) {
                $dropped = array_shift($target['buffer']);
                $target['discarded']++;
                if (null !== $this->overflowCallback) {
                    ($this->overflowCallback)($key, $dropped, $target['discarded']);
                }
            }

            $target['buffer'][] = $buffered;
        }
        unset($target);

        return false === $this->bubble;
    }

    /**
     * Flushes every target independently.
     *
     * Targets with a pending failed batch retry exactly that batch (and
     * only that batch: records accumulated since the failure are kept for
     * the next flush so they go out as a separate, later batch). Targets
     * without a pending batch flush their current buffer. A failure only
     * affects the target it happened on: its batch is kept for the next
     * retry, the error is stored (see getError()) and all other targets
     * are still committed.
     */
    public function flush(): void
    {
        foreach ($this->targets as &$target) {
            if (null !== $target['pending']) {
                // Retry the failed batch. The pending snapshot itself is
                // never handed out; the nested handler receives clones so
                // it cannot corrupt the batch that may need to be retried
                // again. The current buffer is left untouched: it goes out
                // as the next batch once this retry has succeeded.
                try {
                    $target['handler']->handleBatch(array_map(fn (LogRecord $record) => clone $record, $target['pending']));
                    $target['pending'] = null;
                    $target['error'] = null;
                } catch (\Throwable $e) {
                    $target['error'] = $e;
                }

                continue;
            }

            if (\count($target['buffer']) === 0) {
                continue;
            }

            $batch = $target['buffer'];
            $target['buffer'] = [];

            try {
                $target['handler']->handleBatch(array_map(fn (LogRecord $record) => clone $record, $batch));
            } catch (\Throwable $e) {
                // Keep the pristine originals (the handler only received
                // clones) as the pending batch for the next flush. Records
                // arriving from now on accumulate into a fresh buffer and
                // never alter this batch.
                $target['pending'] = $batch;
                $target['error'] = $e;
            }
        }
        unset($target);
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
    public function close(): void
    {
        $this->flush();

        foreach ($this->targets as $target) {
            $target['handler']->close();
        }

        parent::close();
    }

    public function reset(): void
    {
        $this->flush();

        parent::reset();

        $this->resetProcessors();

        foreach ($this->targets as $target) {
            if ($target['handler'] instanceof ResettableInterface) {
                $target['handler']->reset();
            }
        }
    }

    /**
     * Clears all buffers and pending failed batches without flushing
     * anything down to the nested handlers. Discard counters are kept.
     */
    public function clear(): void
    {
        foreach ($this->targets as &$target) {
            $target['buffer'] = [];
            $target['pending'] = null;
            $target['error'] = null;
        }
        unset($target);
    }

    /**
     * Returns the number of records currently buffered for a target
     * (not including a pending failed batch awaiting retry).
     */
    public function getBufferSize(int|string $target): int
    {
        return \count($this->getTarget($target)['buffer']);
    }

    /**
     * Returns the pending failed batch of a target, or null if the
     * target has no batch awaiting retry.
     *
     * @return LogRecord[]|null
     */
    public function getPendingBatch(int|string $target): ?array
    {
        return $this->getTarget($target)['pending'];
    }

    /**
     * Returns the last error a target raised while flushing, or null if
     * the target's last flush succeeded (or it was never flushed).
     */
    public function getError(int|string $target): ?\Throwable
    {
        return $this->getTarget($target)['error'];
    }

    /**
     * Returns how many records were discarded for a target because its
     * buffer reached the configured limit.
     */
    public function getDiscardedCount(int|string $target): int
    {
        return $this->getTarget($target)['discarded'];
    }

    /**
     * @return array{handler: HandlerInterface, buffer: LogRecord[], pending: LogRecord[]|null, error: \Throwable|null, discarded: int}
     */
    protected function getTarget(int|string $target): array
    {
        if (!isset($this->targets[$target])) {
            throw new \InvalidArgumentException('Unknown target "'.$target.'", known targets: '.implode(', ', array_map('strval', array_keys($this->targets))));
        }

        return $this->targets[$target];
    }

    /**
     * @inheritDoc
     */
    public function setFormatter(FormatterInterface $formatter): HandlerInterface
    {
        foreach ($this->targets as $target) {
            if ($target['handler'] instanceof FormattableHandlerInterface) {
                $target['handler']->setFormatter($formatter);
            }
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getFormatter(): FormatterInterface
    {
        foreach ($this->targets as $target) {
            if ($target['handler'] instanceof FormattableHandlerInterface) {
                return $target['handler']->getFormatter();
            }
        }

        throw new \UnexpectedValueException('None of the nested handlers supports formatters.');
    }
}
