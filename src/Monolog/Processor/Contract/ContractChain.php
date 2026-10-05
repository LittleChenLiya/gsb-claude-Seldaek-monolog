<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Processor\Contract;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Wraps an ordered list of processors and enforces an explicit modification contract.
 *
 * Each processor may only modify the LogRecord paths it declares via
 * {@see ContractualProcessorInterface::allowedRecordPaths()}. The chain runs every
 * processor on an independent deep-cloned candidate record, so the original record
 * is never mutated. After each processor, the candidate is verified; any out-of-bounds
 * change aborts the chain before any handler is written.
 *
 * Scalars and arrays are verified deeply. Unknown mutable objects (not DateTimeImmutable,
 * not a backed enum, and without a snapshot provider) make the whole record unverifiable:
 * the chain aborts before invoking any processor, without calling any getter on them.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
class ContractChain implements ProcessorInterface
{
    /**
     * @var list<ProcessorInterface|callable(LogRecord): LogRecord>
     */
    private array $processors;

    /**
     * @var array<class-string, callable(object): (scalar|array<mixed>)>
     */
    private array $objectSnapshots;

    private int $callbackCount = 0;

    /**
     * @param list<ProcessorInterface|callable(LogRecord): LogRecord> $processors      The processors to run, in order
     * @param array<class-string, callable(object): (scalar|array<mixed>)> $objectSnapshots Optional snapshot providers for custom objects
     */
    public function __construct(array $processors, array $objectSnapshots = [])
    {
        $this->processors = $processors;
        $this->objectSnapshots = $objectSnapshots;
    }

    /**
     * Returns the number of processor invocations during the last run.
     */
    public function getCallbackCount(): int
    {
        return $this->callbackCount;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $this->callbackCount = 0;

        $verifiable = true;
        $candidate = $this->deepClone($record, $verifiable);

        if (!$verifiable) {
            throw new ProcessorContractException(sprintf(
                'The log record contains a mutable object that cannot be verified. Provide a snapshot provider to %s or implement %s on the object.',
                self::class,
                RecordObjectSnapshotInterface::class,
            ));
        }

        foreach ($this->processors as $processor) {
            $snapshot = $this->snapshotRecord($candidate);

            $candidate = $processor($candidate);
            $this->callbackCount++;

            if (!$candidate instanceof LogRecord) {
                throw new ProcessorContractException(sprintf(
                    'Processor %s did not return a LogRecord.',
                    $this->describeProcessor($processor),
                ));
            }

            $changes = [];
            $this->diff($snapshot, $this->snapshotRecord($candidate), '', $changes);

            $allowed = $processor instanceof ContractualProcessorInterface
                ? $processor->allowedRecordPaths()
                : [];

            foreach ($changes as $path) {
                if (!$this->isAllowed($path, $allowed)) {
                    throw new ProcessorContractException(sprintf(
                        'Processor %s modified LogRecord path "%s" which is not covered by its allowed paths [%s].',
                        $this->describeProcessor($processor),
                        $path,
                        implode(', ', $allowed),
                    ));
                }
            }
        }

        return $candidate;
    }

    /**
     * Deep-clones a record so processors can run on an independent candidate.
     *
     * Arrays are cloned recursively so nested array references are isolated. Immutable
     * objects (DateTimeImmutable, enums) are shared. Objects with a snapshot provider
     * are cloned. Any other object is marked unverifiable.
     */
    private function deepClone(LogRecord $record, bool &$verifiable): LogRecord
    {
        return new LogRecord(
            datetime: $record->datetime,
            channel: $record->channel,
            level: $record->level,
            message: $record->message,
            context: $this->cloneValue($record->context, $verifiable),
            extra: $this->cloneValue($record->extra, $verifiable),
            formatted: $this->cloneValue($record->formatted, $verifiable),
        );
    }

    private function cloneValue(mixed $value, bool &$verifiable): mixed
    {
        if (\is_array($value)) {
            $clone = [];
            foreach ($value as $key => $item) {
                $clone[$key] = $this->cloneValue($item, $verifiable);
            }

            return $clone;
        }

        if (\is_object($value)) {
            if ($value instanceof \DateTimeImmutable || $value instanceof \UnitEnum) {
                return $value;
            }
            if ($this->resolveSnapshot($value) === null) {
                $verifiable = false;

                return $value;
            }

            return clone $value;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotRecord(LogRecord $record): array
    {
        return [
            'datetime' => $record->datetime,
            'channel' => $record->channel,
            'level' => $record->level,
            'message' => $record->message,
            'context' => $this->snapshotValue($record->context),
            'extra' => $this->snapshotValue($record->extra),
            'formatted' => $this->snapshotValue($record->formatted),
        ];
    }

    private function snapshotValue(mixed $value): mixed
    {
        if (\is_array($value)) {
            $snapshot = [];
            foreach ($value as $key => $item) {
                $snapshot[$key] = $this->snapshotValue($item);
            }

            return $snapshot;
        }

        if (\is_object($value)) {
            if ($value instanceof \UnitEnum || $value instanceof \DateTimeInterface) {
                return $value;
            }

            $snapshot = $this->resolveSnapshot($value);
            if ($snapshot !== null) {
                return $snapshot;
            }

            return $value;
        }

        return $value;
    }

    /**
     * Recursively collects the paths that changed between two snapshots.
     *
     * @param list<string> $changes
     */
    private function diff(mixed $before, mixed $after, string $path, array &$changes): void
    {
        if (\is_array($before) && \is_array($after)) {
            foreach (array_keys($before + $after) as $key) {
                $childPath = $path === '' ? (string) $key : $path.'.'.$key;
                if (!\array_key_exists($key, $before) || !\array_key_exists($key, $after)) {
                    $changes[] = $childPath;
                    continue;
                }
                $this->diff($before[$key], $after[$key], $childPath, $changes);
            }

            return;
        }

        if (\is_object($before) !== \is_object($after)) {
            $changes[] = $path;

            return;
        }

        if (\is_object($before)) {
            if (!$this->objectsEqual($before, $after)) {
                $changes[] = $path;
            }

            return;
        }

        if ($before !== $after) {
            $changes[] = $path;
        }
    }

    private function objectsEqual(object $a, object $b): bool
    {
        if ($a::class !== $b::class) {
            return false;
        }
        if ($a instanceof \UnitEnum) {
            return $a === $b;
        }
        if ($a instanceof \DateTimeInterface) {
            // DateTimeImmutable is immutable: a processor can only replace it, not
            // mutate it in place, so identity comparison is sufficient.
            return $a === $b;
        }

        $snapA = $this->resolveSnapshot($a);
        $snapB = $this->resolveSnapshot($b);
        if ($snapA === null || $snapB === null) {
            return $a === $b;
        }

        return $snapA === $snapB;
    }

    private function resolveSnapshot(object $object): mixed
    {
        if ($object instanceof RecordObjectSnapshotInterface) {
            return $object->snapshotForRecordContract();
        }

        foreach ($this->objectSnapshots as $class => $provider) {
            if ($object instanceof $class) {
                return $provider($object);
            }
        }

        return null;
    }

    /**
     * @param list<string> $allowedPaths
     */
    private function isAllowed(string $path, array $allowedPaths): bool
    {
        foreach ($allowedPaths as $allowed) {
            if ($path === $allowed || str_starts_with($path, $allowed.'.')) {
                return true;
            }
        }

        return false;
    }

    private function describeProcessor(mixed $processor): string
    {
        if (\is_object($processor)) {
            return $processor::class;
        }
        if (\is_string($processor)) {
            return $processor;
        }

        return 'closure';
    }
}
