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

use Monolog\LogRecord;
use Monolog\ResettableInterface;

/**
 * Routes records to different handlers based on the record's channel.
 *
 * Rules map a channel prefix to a handler and are matched on full channel
 * segments: the rule `app` matches the channels `app` and `app.web`, but
 * not `application`. When several rules match, the most specific (longest)
 * prefix wins; rules of equal specificity are resolved in declaration order.
 * If no rule matches, an optional fallback handler is used; without a
 * fallback the record is simply not handled here and keeps bubbling.
 *
 * The rule set is snapshotted at construction time and cannot be mutated
 * from the outside. close() and reset() are forwarded exactly once to every
 * distinct target handler, even when several rules share the same handler.
 *
 * Exceptions thrown by a routed handler are propagated as-is: the record is
 * never re-dispatched to the fallback or any other handler, so a failing
 * target can never cause duplicate writes elsewhere.
 *
 * @author Monolog contributors
 */
class ChannelRoutingHandler extends Handler implements ProcessableHandlerInterface, ResettableInterface
{
    use ProcessableHandlerTrait;

    /**
     * Normalized rules as [prefix, handler] pairs, sorted by descending
     * prefix length; pairs of equal length keep their declaration order.
     *
     * @var list<array{string, HandlerInterface}>
     */
    private array $rules;

    /** @var array<string, HandlerInterface|null> resolved channel => target cache */
    private array $resolved = [];

    /** @var list<HandlerInterface> distinct handlers across all rules and the fallback */
    private array $uniqueHandlers;

    private HandlerInterface|null $fallback;

    private bool $bubble;

    /**
     * @param array<string, HandlerInterface>|list<array{string, HandlerInterface}> $rules
     *        Either a map of channel prefix => handler (declaration order is the
     *        array order), or a list of [prefix, handler] pairs. The pair form
     *        allows declaring the same prefix several times, in which case the
     *        first declaration wins.
     * @param HandlerInterface|null $fallback Handler used when no rule matches a record's channel
     * @param bool                $bubble   Whether records that were routed may bubble up the stack or not
     *
     * @throws \InvalidArgumentException if a rule prefix is not a non-empty string or a rule target is not a handler
     */
    public function __construct(array $rules, HandlerInterface|null $fallback = null, bool $bubble = true)
    {
        $normalized = [];
        foreach ($rules as $prefix => $handler) {
            if (\is_int($prefix) && \is_array($handler)) {
                // pair form: [prefix, handler]
                [$prefix, $handler] = $handler;
            }
            if (!\is_string($prefix) || '' === $prefix) {
                throw new \InvalidArgumentException('ChannelRoutingHandler rule prefixes must be non-empty strings.');
            }
            if (!$handler instanceof HandlerInterface) {
                throw new \InvalidArgumentException('ChannelRoutingHandler rules must map channel prefixes to HandlerInterface instances.');
            }
            $normalized[] = [$prefix, $handler];
        }

        // usort() is stable, so equal-length prefixes keep their declaration order
        usort($normalized, static fn (array $a, array $b): int => \strlen($b[0]) <=> \strlen($a[0]));
        $this->rules = $normalized;

        $unique = [];
        foreach ($normalized as [, $handler]) {
            $unique[\spl_object_id($handler)] = $handler;
        }
        if (null !== $fallback) {
            $unique[\spl_object_id($fallback)] = $fallback;
        }
        $this->uniqueHandlers = \array_values($unique);

        $this->fallback = $fallback;
        $this->bubble = $bubble;
    }

    /**
     * @inheritDoc
     */
    public function isHandling(LogRecord $record): bool
    {
        $handler = $this->resolveHandler($record->channel);

        return null !== $handler && $handler->isHandling($record);
    }

    /**
     * @inheritDoc
     */
    public function handle(LogRecord $record): bool
    {
        $handler = $this->resolveHandler($record->channel);
        if (null === $handler) {
            // no matching rule and no fallback: not handled here, keep bubbling
            return false;
        }

        if (\count($this->processors) > 0) {
            $record = $this->processRecord($record);
        }

        // Exceptions of the target propagate to the caller on purpose: the
        // record is never re-dispatched to the fallback or another rule, so
        // a failing target cannot cause duplicate writes elsewhere.
        $handler->handle($record);

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

        /** @var array<int, array{HandlerInterface, list<LogRecord>}> $routed */
        $routed = [];
        foreach ($records as $record) {
            $handler = $this->resolveHandler($record->channel);
            if (null === $handler) {
                continue;
            }
            $id = \spl_object_id($handler);
            if (!isset($routed[$id])) {
                $routed[$id] = [$handler, []];
            }
            $routed[$id][1][] = $record;
        }

        foreach ($routed as [$handler, $handlerRecords]) {
            $handler->handleBatch($handlerRecords);
        }
    }

    public function reset(): void
    {
        $this->resetProcessors();
        $this->resolved = [];

        foreach ($this->uniqueHandlers as $handler) {
            if ($handler instanceof ResettableInterface) {
                $handler->reset();
            }
        }
    }

    public function close(): void
    {
        parent::close();

        foreach ($this->uniqueHandlers as $handler) {
            $handler->close();
        }
    }

    /**
     * Returns the handler a channel would be routed to (a rule target or the
     * fallback), or null when no rule matches and no fallback is configured.
     */
    public function getHandlerForChannel(string $channel): HandlerInterface|null
    {
        return $this->resolveHandler($channel);
    }

    private function resolveHandler(string $channel): HandlerInterface|null
    {
        if (\array_key_exists($channel, $this->resolved)) {
            return $this->resolved[$channel];
        }

        foreach ($this->rules as [$prefix, $handler]) {
            if ($channel === $prefix || str_starts_with($channel, $prefix . '.')) {
                return $this->resolved[$channel] = $handler;
            }
        }

        return $this->resolved[$channel] = $this->fallback;
    }
}
