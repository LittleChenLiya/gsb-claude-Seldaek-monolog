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
use Monolog\ResettableInterface;

/**
 * Counts duplicate log records while preserving the first occurrence.
 *
 * Each record is assigned a sequence number that increments every time the
 * same identity is seen. The identity is composed of the channel, level,
 * message, and caller-selected scalar context fields. The cache has a fixed
 * capacity and evicts the least recently used entries when full.
 *
 * The original record is never modified; a new record with the sequence
 * number in the extra field is returned.
 */
class DuplicateCounterProcessor implements ProcessorInterface, ResettableInterface
{
    /**
     * @var int<1, max>
     */
    private int $capacity;

    /**
     * @var string[]
     */
    private array $contextFields;

    /**
     * @var array<string, int> key => count, ordered oldest-first (LRU)
     */
    private array $cache = [];

    /**
     * @param int<1, max> $capacity      Maximum number of identities to track
     * @param string[]    $contextFields Context field names to include in identity
     */
    public function __construct(int $capacity, array $contextFields = [])
    {
        if ($capacity < 1) {
            throw new \InvalidArgumentException('Capacity must be at least 1');
        }
        $this->capacity = $capacity;
        $fields = array_values(array_unique($contextFields));
        sort($fields);
        $this->contextFields = $fields;
    }

    /**
     * @inheritDoc
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $key = $this->buildKey($record);

        if (isset($this->cache[$key])) {
            // Move to end (most recently used) and increment
            $count = $this->cache[$key];
            unset($this->cache[$key]);
            $this->cache[$key] = $count + 1;
            $sequence = $count + 1;
        } else {
            // New identity - evict LRU entry if at capacity
            if (count($this->cache) >= $this->capacity) {
                array_shift($this->cache);
            }
            $this->cache[$key] = 1;
            $sequence = 1;
        }

        return $record->with(extra: array_merge($record->extra, [
            'duplicate_count' => $sequence,
        ]));
    }

    /**
     * @inheritDoc
     */
    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * Build a unique identity key for the record.
     */
    private function buildKey(LogRecord $record): string
    {
        $components = [];
        $components[] = $this->encodeComponent($record->channel);
        $components[] = $this->encodeComponent((string) $record->level->value);
        $components[] = $this->encodeComponent($record->message);

        foreach ($this->contextFields as $field) {
            $components[] = $this->encodeComponent($field);
            if (!array_key_exists($field, $record->context)) {
                $components[] = $this->encodeComponent('missing');
            } else {
                $value = $record->context[$field];
                $type = $this->getValueType($value, $field);
                $components[] = $this->encodeComponent($type);
                $components[] = $this->encodeComponent($this->getScalarValue($value, $type));
            }
        }

        return sha1(implode('', $components));
    }

    /**
     * Encode a component with its byte length to avoid collisions.
     */
    private function encodeComponent(string $value): string
    {
        return strlen($value) . ':' . $value;
    }

    /**
     * Get the type tag for a context value.
     *
     * @throws \InvalidArgumentException if the value is not scalar or null
     */
    private function getValueType(mixed $value, string $field): string
    {
        return match (true) {
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_string($value) => 'string',
            is_bool($value) => 'bool',
            is_null($value) => 'null',
            default => throw new \InvalidArgumentException(sprintf(
                'Context field "%s" value must be scalar or null, %s given',
                $field,
                get_debug_type($value)
            )),
        };
    }

    /**
     * Get the scalar string representation of a context value.
     */
    private function getScalarValue(mixed $value, string $type): string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'null' => '',
            default => (string) $value,
        };
    }
}
