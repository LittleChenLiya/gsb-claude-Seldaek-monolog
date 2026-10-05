<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Processor;

use Monolog\LogRecord;

/**
 * Deep isolation and verification engine for the contract processor chain.
 *
 * Verification rules:
 *  - scalars, null and arrays (recursively) are deep-copied and deep-compared
 *  - \DateTimeImmutable and enums are immutable, shared by reference and compared by value
 *  - \DateTime is cloned for isolation and compared by value
 *  - objects of classes with a registered verifier are cloned for isolation and
 *    compared through the snapshot returned by the verifier
 *  - any other mutable object is NOT verifiable: it is never inspected (no getters
 *    are called) and its presence aborts the chain before any callback runs
 *
 * @internal
 *
 * @author Monolog contributors
 */
final class ContractVerifier
{
    /** @var array<class-string, callable(object): mixed> */
    private array $verifiers = [];

    /** @var array<class-string, \ReflectionClass<object>> */
    private array $cloneableCache = [];

    /**
     * Registers a verifier for a custom value type. The callback receives the object
     * and must return a deep-verifiable snapshot of it (scalars and/or arrays only).
     *
     * @param class-string            $class
     * @param callable(object): mixed $snapshotter
     */
    public function registerVerifier(string $class, callable $snapshotter): void
    {
        $this->verifiers[$class] = $snapshotter;
    }

    /**
     * Returns the dot-notated path of the first non-verifiable value in the record,
     * or null when every value can be verified.
     */
    public function findNonVerifiable(LogRecord $record): ?string
    {
        foreach (['datetime', 'channel', 'level', 'message', 'context', 'extra', 'formatted'] as $field) {
            if (null !== $path = $this->findNonVerifiableValue($record->{$field}, $field)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Creates an independent candidate record: arrays are deep-copied (breaking
     * array references), mutable known objects are cloned, immutable known values
     * are shared. The original record can therefore never be altered through the
     * candidate.
     */
    public function isolate(LogRecord $record): LogRecord
    {
        return new LogRecord(
            datetime: $record->datetime,
            channel: $record->channel,
            level: $record->level,
            message: $record->message,
            context: $this->isolateValue($record->context),
            extra: $this->isolateValue($record->extra),
            formatted: $this->isolateValue($record->formatted),
        );
    }

    /**
     * Takes a deep snapshot of every record field, to be compared against the
     * processor result. Verifier-registered objects are stored as their
     * verifier snapshot.
     *
     * @return array<string, mixed>
     */
    public function snapshot(LogRecord $record): array
    {
        $snapshot = [];
        foreach (['datetime', 'channel', 'level', 'message', 'context', 'extra', 'formatted'] as $field) {
            $snapshot[$field] = $this->snapshotValue($record->{$field});
        }

        return $snapshot;
    }

    /**
     * Deep-compares a snapshot against a processor result, restricted to the paths
     * the contract does NOT allow. Returns the first violated path or null.
     *
     * @param array<string, mixed> $snapshot
     */
    public function verify(array $snapshot, LogRecord $result, ProcessorContract $contract): ?string
    {
        foreach (['datetime', 'channel', 'level', 'message', 'context', 'extra', 'formatted'] as $field) {
            if (null !== $path = $this->diff($snapshot[$field], $result->{$field}, $field, $contract)) {
                return $path;
            }
        }

        return null;
    }

    private function findNonVerifiableValue(mixed $value, string $path): ?string
    {
        if ($value === null || \is_scalar($value)) {
            return null;
        }

        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                if (null !== $found = $this->findNonVerifiableValue($item, $path.'.'.$key)) {
                    return $found;
                }
            }

            return null;
        }

        if ($value instanceof \DateTimeImmutable || $value instanceof \UnitEnum) {
            return null;
        }

        if ($value instanceof \DateTime) {
            return null;
        }

        if (\is_object($value)) {
            if (null !== $this->verifierFor($value) && $this->isCloneable($value)) {
                return null;
            }

            // unknown mutable object: flagged non-verifiable without calling any getter
            return $path;
        }

        // resources and anything else cannot be verified
        return $path;
    }

    private function isolateValue(mixed $value): mixed
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }

        if (\is_array($value)) {
            $copy = [];
            foreach ($value as $key => $item) {
                // assigning by value breaks array references, isolating nested arrays
                $copy[$key] = $this->isolateValue($item);
            }

            return $copy;
        }

        if ($value instanceof \DateTimeImmutable || $value instanceof \UnitEnum) {
            return $value;
        }

        if (\is_object($value)) {
            // \DateTime and verifier-registered objects are cloned so the candidate
            // cannot mutate the values of the original record
            return clone $value;
        }

        throw new \LogicException('Cannot isolate non-verifiable value, run findNonVerifiable() first');
    }

    private function snapshotValue(mixed $value): mixed
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }

        if (\is_array($value)) {
            $copy = [];
            foreach ($value as $key => $item) {
                $copy[$key] = $this->snapshotValue($item);
            }

            return $copy;
        }

        if ($value instanceof \DateTimeImmutable || $value instanceof \UnitEnum) {
            return $value;
        }

        if (\is_object($value)) {
            $verifier = $this->verifierFor($value);
            if (null !== $verifier) {
                return $this->snapshotValue($verifier($value));
            }

            return clone $value;
        }

        throw new \LogicException('Cannot snapshot non-verifiable value, run findNonVerifiable() first');
    }

    private function diff(mixed $snapshot, mixed $current, string $path, ProcessorContract $contract): ?string
    {
        if ($contract->allows($path)) {
            return null;
        }

        if (\is_object($current) && null !== $verifier = $this->verifierFor($current)) {
            // the snapshot side holds the verifier snapshot of the original object
            return $this->diffPlain($snapshot, $this->snapshotValue($verifier($current)), $path);
        }

        if (\is_object($snapshot) || \is_object($current)) {
            if ($snapshot instanceof \DateTimeInterface && $current instanceof \DateTimeInterface) {
                return $snapshot == $current ? null : $path;
            }

            if ($snapshot instanceof \UnitEnum && $current instanceof \UnitEnum) {
                return $snapshot === $current ? null : $path;
            }

            return $path;
        }

        if (\is_array($snapshot) && \is_array($current)) {
            foreach ($current as $key => $item) {
                if (!\array_key_exists($key, $snapshot)) {
                    // added key: report it unless the contract allows that exact path
                    if (!$contract->allows($path.'.'.$key)) {
                        return $path.'.'.$key;
                    }

                    continue;
                }
                if (null !== $found = $this->diff($snapshot[$key], $item, $path.'.'.$key, $contract)) {
                    return $found;
                }
            }
            foreach ($snapshot as $key => $item) {
                if (!\array_key_exists($key, $current) && !$contract->allows($path.'.'.$key)) {
                    // removed key
                    return $path.'.'.$key;
                }
            }

            return null;
        }

        return $this->diffPlain($snapshot, $current, $path);
    }

    private function diffPlain(mixed $snapshot, mixed $current, string $path): ?string
    {
        if (\is_array($snapshot) && \is_array($current)) {
            if (array_keys($snapshot) !== array_keys($current)) {
                return $path;
            }
            foreach ($snapshot as $key => $item) {
                if (null !== $found = $this->diffPlain($item, $current[$key], $path.'.'.$key)) {
                    return $found;
                }
            }

            return null;
        }

        if (\gettype($snapshot) !== \gettype($current)) {
            return $path;
        }

        if (\is_array($snapshot) || \is_object($snapshot)) {
            return $path;
        }

        return $snapshot === $current ? null : $path;
    }

    /**
     * @return null|callable(object): mixed
     */
    private function verifierFor(object $value): ?callable
    {
        foreach ($this->verifiers as $class => $verifier) {
            if ($value instanceof $class) {
                return $verifier;
            }
        }

        return null;
    }

    private function isCloneable(object $value): bool
    {
        $class = $value::class;
        if (!isset($this->cloneableCache[$class])) {
            $this->cloneableCache[$class] = new \ReflectionClass($value);
        }

        return $this->cloneableCache[$class]->isCloneable();
    }
}
