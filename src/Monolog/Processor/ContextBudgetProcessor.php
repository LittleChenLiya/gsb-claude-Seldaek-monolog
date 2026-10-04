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
 * Limits the estimated size of a record context to a byte budget.
 *
 * Context entries are evaluated in their original key order and kept whole as
 * long as the running total stays within the configured budget. Entries that
 * do not fit are dropped; a truncation summary with their key paths and
 * estimated byte sizes is added to the record extra data.
 *
 * The estimate intentionally does NOT JSON-encode values (so user code such as
 * JsonSerializable::jsonSerialize is never executed and the cost of encoding
 * is never paid repeatedly). It is a deterministic approximation of the
 * serialized size of the data structure:
 *
 *  - scalar strings are measured in UTF-8 bytes (multibyte characters count as
 *    more than one byte, never as one character); keys are measured the same
 *    way
 *  - integers/floats count as the byte length of their canonical string form
 *  - null counts as 4 bytes, booleans as 4 (true) / 5 (false) bytes
 *  - enums are measured from their scalar backing value or their name, without
 *    calling any user code
 *  - unknown objects are treated as opaque leaves and estimated from their
 *    class name only (e.g. "[object Foo\\Bar]"); the object instance itself is
 *    kept untouched when the entry fits
 *  - resources are estimated as "[resource type]"
 *  - every string key adds the raw UTF-8 byte length of the key plus one byte
 *    for the colon separating it from the value
 *  - arrays carry a fixed structural cost of two bytes for the surrounding
 *    brackets/braces plus one byte per element (after the first) for the
 *    separator between elements
 *  - nested arrays are atomic: an entry is either kept completely with all of
 *    its descendants or it is dropped entirely, a value is never partially
 *    truncated
 *
 * The processor holds no per-record mutable state, so the same instance can
 * process any number of records and each record starts with a fresh
 * accumulator. The incoming record is never mutated; either it is returned
 * unchanged or a new record is returned.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ContextBudgetProcessor implements ProcessorInterface
{
    /**
     * Fixed structural byte costs used by the estimate.
     */
    private const COST_PAIR_COLON = 1;
    private const COST_CONTAINER = 2;
    private const COST_SEPARATOR = 1;
    private const COST_NULL = 4;
    private const COST_TRUE = 4;
    private const COST_FALSE = 5;
    private const COST_OBJECT_PREFIX = 8; // "[object "
    private const COST_OBJECT_SUFFIX = 1; // "]"
    private const COST_RESOURCE_PREFIX = 10; // "[resource "
    private const COST_RESOURCE_SUFFIX = 1; // "]"
    private const COST_DEEP_ARRAY = 7; // "[array]"

    /**
     * Arrays nested deeper than this are treated as opaque leaves so that a
     * pathological recursion depth cannot exhaust the call stack.
     */
    public const MAX_DEPTH = 128;

    private int $budget;
    private string $extraKey;

    /**
     * @param int    $budget   maximum estimated number of UTF-8 bytes the context may consume
     * @param string $extraKey key under which the truncation summary is stored in $extra
     */
    public function __construct(int $budget = 1024, string $extraKey = 'context_budget')
    {
        if ($budget < 0) {
            throw new \InvalidArgumentException('The budget must be zero or a positive number, got '.$budget);
        }

        $this->budget = $budget;
        $this->extraKey = $extraKey;
    }

    /**
     * @inheritDoc
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        // All accumulators are local to this call, so two records processed by
        // the same processor can never share running totals.
        $used = 0;
        $kept = [];
        $dropped = [];

        foreach ($record->context as $key => $value) {
            $cost = self::COST_PAIR_COLON + self::utf8Length((string) $key) + self::estimateValue($value, 1);

            if ($used + $cost <= $this->budget) {
                $used += $cost;
                $kept[$key] = $value;

                continue;
            }

            // Nested entries are rejected as a whole: $value is reported as-is
            // in the dropped summary, never partially trimmed.
            $dropped[] = [
                'path' => (string) $key,
                'bytes' => $cost,
            ];
        }

        if (\count($dropped) === 0) {
            return $record;
        }

        $extra = $record->extra;
        $extra[$this->extraKey] = [
            'budget' => $this->budget,
            'used' => $used,
            'dropped' => $dropped,
        ];

        return $record->with(context: $kept, extra: $extra);
    }

    /**
     * Estimates the serialized size of a single value without executing any
     * user code and without JSON-encoding the value.
     *
     * @param int $depth nesting depth of the value, 1 for a top-level entry value
     */
    private static function estimateValue(mixed $value, int $depth): int
    {
        if ($value === null) {
            return self::COST_NULL;
        }

        if (\is_bool($value)) {
            return $value ? self::COST_TRUE : self::COST_FALSE;
        }

        if (\is_int($value)) {
            return self::utf8Length((string) $value);
        }

        if (\is_float($value)) {
            if (!is_finite($value)) {
                // json_encode emits "null" for NAN/INF with partial output on error.
                return self::COST_NULL;
            }

            $encoded = json_encode($value);

            return self::utf8Length($encoded === false ? (string) $value : $encoded);
        }

        if (\is_string($value)) {
            return self::utf8Length($value);
        }

        if (\is_array($value)) {
            return self::estimateArray($value, $depth);
        }

        if ($value instanceof \BackedEnum) {
            return self::utf8Length((string) $value->value);
        }

        if ($value instanceof \UnitEnum) {
            return self::utf8Length($value->name);
        }

        if (\is_resource($value)) {
            return self::COST_RESOURCE_PREFIX + self::utf8Length(\get_resource_type($value)) + self::COST_RESOURCE_SUFFIX;
        }

        if (\is_object($value)) {
            // Opaque leaf: class name only, no __toString / jsonSerialize calls.
            return self::COST_OBJECT_PREFIX + self::utf8Length($value::class) + self::COST_OBJECT_SUFFIX;
        }

        // gettype fallback for value types PHP might add in the future.
        return self::COST_OBJECT_PREFIX + self::utf8Length(\gettype($value)) + self::COST_OBJECT_SUFFIX;
    }

    /**
     * Estimates an array including its fixed structural overhead.
     *
     * @param array<mixed> $value
     */
    private static function estimateArray(array $value, int $depth): int
    {
        if ($depth >= self::MAX_DEPTH) {
            // Opaque leaf past the safety depth; the value is still preserved as a whole.
            return self::COST_DEEP_ARRAY;
        }

        $cost = self::COST_CONTAINER;
        $first = true;
        foreach ($value as $childKey => $childValue) {
            if (!$first) {
                $cost += self::COST_SEPARATOR;
            }
            $first = false;

            if (\is_int($childKey)) {
                // list element: no serialized key, no colon
                $cost += self::estimateValue($childValue, $depth + 1);
            } else {
                $cost += self::COST_PAIR_COLON + self::utf8Length((string) $childKey)
                    + self::estimateValue($childValue, $depth + 1);
            }
        }

        return $cost;
    }

    /**
     * Returns the length of a string in UTF-8 bytes. strlen counts bytes
     * rather than characters, which is exactly what the budget needs and
     * avoids depending on the optional mbstring extension.
     */
    private static function utf8Length(string $value): int
    {
        return \strlen($value);
    }
}
