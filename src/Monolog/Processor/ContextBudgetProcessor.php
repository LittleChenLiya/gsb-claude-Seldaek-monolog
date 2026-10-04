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
use Monolog\Utils;

/**
 * Keeps the context of a log record within a fixed byte budget so that a
 * single log record cannot be blown up by an oversized context field.
 *
 * Budget accounting rules (all amounts are UTF-8 bytes, the processor never
 * json_encode()s a value repeatedly to measure it):
 *  - Strings: 2 quote bytes plus the raw UTF-8 byte length (strlen(), which
 *    counts bytes, not characters - so "中文" costs 6, not 2).
 *  - Integers and floats: the byte length of their scalar representation
 *    ((string) cast, which runs no user code).
 *  - null/bools: 4 bytes for "null"/"true", 5 bytes for "false".
 *  - Keys: 2 quote bytes plus the UTF-8 key bytes plus 1 colon byte.
 *  - Fixed structural overhead: 2 bytes per array/object container
 *    ("{}" / "[]") and 1 comma byte per entry (the comma is counted for every
 *    entry, including the last one, which is a deliberate 1-byte
 *    over-estimate that keeps the accounting branch-free).
 *  - Objects never execute user code: __toString() and jsonSerialize() are
 *    not called. Unknown objects are costed as the placeholder string
 *    "[object FQCN]"; BackedEnum are costed as their backing scalar and
 *    UnitEnum by their name.
 *
 * Entries are evaluated in original key order: an entry that fits the
 * remaining budget is kept, an entry that does not fit is dropped and
 * recorded (with its estimated byte cost) in the summary. Nested entries
 * (arrays) are costed as a whole and kept or rejected as a whole - no half
 * values are ever left behind. The original LogRecord is never mutated, a new
 * record is returned, and all accounting state is local to each __invoke()
 * call so consecutive calls never share accumulated state.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ContextBudgetProcessor implements ProcessorInterface
{
    /** 2 bytes for the opening and closing braces/brackets of a container */
    private const CONTAINER_OVERHEAD = 2;
    /** 2 quotes around a JSON string key */
    private const KEY_QUOTES_OVERHEAD = 2;
    /** 1 colon between a key and its value */
    private const COLON_OVERHEAD = 1;
    /** 1 comma after every entry (the last entry is counted as well) */
    private const COMMA_OVERHEAD = 1;
    /** 2 quotes around a JSON string value */
    private const STRING_QUOTES_OVERHEAD = 2;

    private const NULL_COST = 4;  // null
    private const TRUE_COST = 4;  // true
    private const FALSE_COST = 5; // false

    /**
     * @param int    $maxBytes   The maximum number of bytes the kept context may occupy
     * @param string $summaryKey The key under which the truncation summary is written
     */
    public function __construct(
        private int $maxBytes,
        private string $summaryKey = '_budget',
    ) {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('The context budget must be at least 1 byte, '.$maxBytes.' given.');
        }
    }

    /**
     * @inheritDoc
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        // All accounting state is local to this call: consecutive calls on
        // the same processor instance therefore never share accumulated state.
        $context = $record->context;
        $kept = [];
        $dropped = [];
        $usedBytes = self::CONTAINER_OVERHEAD;
        $remaining = $this->maxBytes - self::CONTAINER_OVERHEAD;

        foreach ($context as $key => $value) {
            $cost = $this->entryCost($key, $value);
            if ($cost <= $remaining) {
                $kept[$key] = $value;
                $remaining -= $cost;
                $usedBytes += $cost;
            } else {
                $dropped[(string) $key] = $cost;
            }
        }

        if (\count($dropped) > 0) {
            $kept[$this->summaryKey] = [
                'budget_bytes' => $this->maxBytes,
                'used_bytes' => $usedBytes,
                'dropped_bytes' => $dropped,
            ];
        }

        return $record->with(context: $kept);
    }

    /**
     * Estimates the JSON-encoded byte cost of a single top-level entry,
     * including its key and structural overhead.
     */
    private function entryCost(int|string $key, mixed $value): int
    {
        $cost = self::KEY_QUOTES_OVERHEAD + \strlen((string) $key) + self::COLON_OVERHEAD;
        $cost += $this->valueCost($value);
        $cost += self::COMMA_OVERHEAD;

        return $cost;
    }

    /**
     * Estimates the JSON-encoded byte cost of a value. Arrays are costed as
     * a whole (recursively) so they can be kept or rejected atomically.
     */
    private function valueCost(mixed $value): int
    {
        return match (true) {
            $value === null => self::NULL_COST,
            $value === true => self::TRUE_COST,
            $value === false => self::FALSE_COST,
            \is_string($value) => self::STRING_QUOTES_OVERHEAD + \strlen($value),
            \is_int($value) => \strlen((string) $value),
            \is_float($value) => \strlen((string) $value),
            \is_array($value) => $this->arrayCost($value),
            \is_object($value) => $this->objectCost($value),
            \is_resource($value) => self::STRING_QUOTES_OVERHEAD + \strlen('[resource:'.\get_resource_type($value).']'),
            default => self::STRING_QUOTES_OVERHEAD + \strlen('['.\gettype($value).']'),
        };
    }

    private function arrayCost(array $value): int
    {
        $cost = self::CONTAINER_OVERHEAD;
        foreach ($value as $key => $item) {
            if (\is_string($key)) {
                $cost += self::KEY_QUOTES_OVERHEAD + \strlen($key) + self::COLON_OVERHEAD;
            }
            $cost += $this->valueCost($item);
            $cost += self::COMMA_OVERHEAD;
        }

        return $cost;
    }

    /**
     * Estimates the byte cost of an object without ever executing user code
     * (no __toString(), no jsonSerialize()).
     */
    private function objectCost(object $value): int
    {
        if ($value instanceof \BackedEnum) {
            return $this->valueCost($value->value);
        }
        if ($value instanceof \UnitEnum) {
            return self::STRING_QUOTES_OVERHEAD + \strlen($value->name);
        }

        // Unknown object: cost the placeholder string json_encode() would
        // produce for a non-Stringable object.
        return self::STRING_QUOTES_OVERHEAD + \strlen('[object '.Utils::getClass($value).']');
    }
}
