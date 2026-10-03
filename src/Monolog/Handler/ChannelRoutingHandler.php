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

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;
use Monolog\ResettableInterface;

/**
 * Routes records to a single handler depending on the record's channel.
 *
 * Rules are keyed by channel prefix and matched against full channel segments:
 * a rule for "app" matches the channels "app" and "app.web", but not
 * "application". The most specific (longest) matching prefix wins; rules with
 * equally specific prefixes are kept in declaration order. Rules can be passed
 * either as a [prefix => handler] map or as a list of [prefix, handler] pairs,
 * the latter allowing several rules to share the same prefix.
 *
 * If no rule matches, the optional fallback handler is used instead. When no
 * fallback is configured either, the record is simply not handled and keeps
 * bubbling through the rest of the handler stack.
 *
 * Exceptions thrown by the target handler are not caught, so a failing target
 * never causes duplicate writes to another target or to the fallback.
 */
class ChannelRoutingHandler extends Handler implements ProcessableHandlerInterface, ResettableInterface
{
    use ProcessableHandlerTrait;

    /**
     * Snapshot of the routing rules as a list of [channel prefix, handler] tuples.
     *
     * Stored as a list of tuples so that later mutations of the $rules argument
     * have no effect on this handler.
     *
     * @var array<array{string, HandlerInterface}>
     */
    protected array $rules;

    protected ?HandlerInterface $fallback;
    protected bool $bubble;

    /**
     * @param array<string, HandlerInterface>|array<array{string, HandlerInterface}> $rules Handlers keyed by the channel prefix they handle, or a list of [prefix, handler] pairs (pairs allow several rules with the same prefix, resolved by declaration order)
     * @param HandlerInterface|null                                                    $fallback Handler used when no rule matches the channel
     * @param bool                                                                     $bubble  Whether the messages that are handled can bubble up the stack or not
     *
     * @throws \InvalidArgumentException if a rule or the fallback is not a HandlerInterface instance
     */
    public function __construct(array $rules, ?HandlerInterface $fallback = null, bool $bubble = true)
    {
        $this->rules = [];
        foreach ($rules as $key => $value) {
            if ($value instanceof HandlerInterface) {
                $prefix = (string) $key;
                $handler = $value;
            } elseif (\is_array($value) && \count($value) === 2 && isset($value[0], $value[1]) && \is_string($value[0]) && $value[1] instanceof HandlerInterface) {
                // List format: [prefix, handler]
                $prefix = $value[0];
                $handler = $value[1];
            } else {
                throw new \InvalidArgumentException('Every rule of the ChannelRoutingHandler must map a channel prefix to a HandlerInterface instance, or be a [prefix, handler] pair.');
            }

            if ($prefix === '' || $prefix[0] === '.' || str_ends_with($prefix, '.') || str_contains($prefix, '..')) {
                throw new \InvalidArgumentException(sprintf('The channel prefix "%s" is invalid. Prefixes must be non-empty and cannot start or end with a dot or contain consecutive dots.', $prefix));
            }
            $this->rules[] = [$prefix, $handler];
        }

        $this->fallback = $fallback;
        $this->bubble = $bubble;
    }

    /**
     * @inheritDoc
     */
    public function isHandling(LogRecord $record): bool
    {
        $handler = $this->getHandlerForChannel($record->channel);

        return $handler !== null && $handler->isHandling($record);
    }

    /**
     * @inheritDoc
     */
    public function handle(LogRecord $record): bool
    {
        if (\count($this->processors) > 0) {
            $record = $this->processRecord($record);
        }

        $handler = $this->getHandlerForChannel($record->channel);
        if ($handler === null) {
            // No rule matched and no fallback was configured: leave the record
            // alone so it keeps bubbling through the handler stack.
            return false;
        }

        // The record is cloned so processors attached to the target handler
        // cannot mutate the record seen by the rest of the stack.
        // Exceptions are deliberately not caught: a failing target must not
        // trigger a write to another target or to the fallback.
        $handler->handle(clone $record);

        return false === $this->bubble;
    }

    /**
     * @inheritDoc
     */
    public function handleBatch(array $records): void
    {
        if (\count($this->processors) > 0) {
            $processed = [];
            foreach ($records as $record) {
                $processed[] = $this->processRecord($record);
            }
            $records = $processed;
        }

        // Group records by their target handler so each handler receives a
        // single batch, while shared handlers are only called once.
        $batches = [];
        foreach ($records as $record) {
            $handler = $this->getHandlerForChannel($record->channel);
            if ($handler === null) {
                continue;
            }
            $id = spl_object_id($handler);
            $batches[$id]['handler'] = $handler;
            $batches[$id]['records'][] = clone $record;
        }

        foreach ($batches as $batch) {
            // Exceptions are deliberately not caught, exactly like in handle().
            $batch['handler']->handleBatch($batch['records']);
        }
    }

    public function reset(): void
    {
        $this->resetProcessors();

        $seen = [];
        foreach ($this->rules as $rule) {
            $handler = $rule[1];
            $id = spl_object_id($handler);
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                if ($handler instanceof ResettableInterface) {
                    $handler->reset();
                }
            }
        }
        if ($this->fallback !== null) {
            $id = spl_object_id($this->fallback);
            if (!isset($seen[$id]) && $this->fallback instanceof ResettableInterface) {
                $this->fallback->reset();
            }
        }
    }

    public function close(): void
    {
        parent::close();

        $seen = [];
        foreach ($this->rules as $rule) {
            $handler = $rule[1];
            $id = spl_object_id($handler);
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $handler->close();
            }
        }
        if ($this->fallback !== null) {
            $id = spl_object_id($this->fallback);
            if (!isset($seen[$id])) {
                $this->fallback->close();
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function setFormatter(FormatterInterface $formatter): HandlerInterface
    {
        $seen = [];
        foreach ($this->rules as $rule) {
            $handler = $rule[1];
            $id = spl_object_id($handler);
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                if ($handler instanceof FormattableHandlerInterface) {
                    $handler->setFormatter($formatter);
                }
            }
        }
        if ($this->fallback !== null) {
            $id = spl_object_id($this->fallback);
            if (!isset($seen[$id]) && $this->fallback instanceof FormattableHandlerInterface) {
                $this->fallback->setFormatter($formatter);
            }
        }

        return $this;
    }

    /**
     * Finds the handler matching the given channel.
     *
     * A rule matches when the channel is exactly the prefix or starts with the
     * prefix followed by a dot, so "app" matches "app" and "app.web" but not
     * "application". The longest matching prefix wins; ties are resolved by
     * declaration order. When no rule matches, the fallback handler is returned.
     */
    protected function getHandlerForChannel(string $channel): ?HandlerInterface
    {
        $matchedHandler = null;
        $matchedLength = -1;

        foreach ($this->rules as $rule) {
            [$prefix, $handler] = $rule;
            if ($channel !== $prefix && !str_starts_with($channel, $prefix . '.')) {
                continue;
            }

            $prefixLength = \strlen($prefix);
            if ($prefixLength > $matchedLength) {
                $matchedLength = $prefixLength;
                $matchedHandler = $handler;
            }
        }

        return $matchedHandler ?? $this->fallback;
    }
}
