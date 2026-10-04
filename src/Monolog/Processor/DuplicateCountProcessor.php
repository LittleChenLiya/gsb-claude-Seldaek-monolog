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
 * Counts duplicate log records and appends the occurrence sequence number
 * to the record's extra data, without dropping any record.
 *
 * Unlike a deduplication handler (which buffers or drops repeats), every
 * record is returned. The first occurrence of an identity gets sequence
 * number 1, the second 2, and so on.
 *
 * Identity is composed of:
 *  - the record channel
 *  - the record level
 *  - the record message
 *  - the caller-selected scalar context fields (see $contextFields)
 *
 * The record datetime is intentionally NOT part of the identity: no clock
 * is involved in deciding whether two records are duplicates.
 *
 * Context field values are matched strictly by type and value: the integer
 * 1, the string "1" and a missing/null field are three different things.
 * A field that is absent from the context and a field explicitly set to
 * null are treated as the same "nil" bucket. Non-scalar values (objects,
 * arrays, resources, ...) are rejected with an InvalidArgumentException:
 * the processor never serializes or stringifies unknown values to guess
 * an identity for them.
 *
 * The identity cache is explicitly bounded: it holds at most $maxEntries
 * identities. When the cache is full and a new identity arrives, the least
 * recently used identity is evicted. If a record for an evicted identity
 * shows up again, its sequence restarts at 1. Calling reset() empties the
 * cache, so the next occurrence of any identity is numbered 1 again.
 *
 * The processor is side-effect free with regard to its input: the given
 * record is never modified, a cloned record carrying the sequence number
 * in $record->extra[$extraKey] is returned, and no logger configuration
 * is touched.
 */
class DuplicateCountProcessor implements ProcessorInterface, ResettableInterface
{
    /**
     * Context field names participating in the identity, sorted and
     * deduplicated so that the order in which fields were listed does
     * not change the identity.
     *
     * @var list<string>
     */
    private array $contextFields;

    /** @var positive-int */
    private int $maxEntries;

    /** @var non-empty-string */
    private string $extraKey;

    /**
     * Identity key => occurrence count. The array is kept in LRU order:
     * the least recently used identity is always the first element.
     *
     * @var array<string, int>
     */
    private array $counts = [];

    /**
     * @param string[] $contextFields Names of the context fields that take
     *                                part in the duplicate identity. Their
     *                                values must be scalars or null.
     * @param int      $maxEntries    Maximum number of identities kept in
     *                                the cache before the least recently
     *                                used one is evicted. Must be >= 1.
     * @param string   $extraKey      Key under which the sequence number is
     *                                written in $record->extra.
     */
    public function __construct(array $contextFields = [], int $maxEntries = 1024, string $extraKey = 'duplicate_count')
    {
        if ($maxEntries < 1) {
            throw new \InvalidArgumentException('The max entries must be a positive integer, got: '.$maxEntries);
        }
        if ('' === $extraKey) {
            throw new \InvalidArgumentException('The extra key must be a non-empty string');
        }

        $fields = [];
        foreach ($contextFields as $field) {
            if (!\is_string($field) || '' === $field) {
                throw new \InvalidArgumentException('Context field names must be non-empty strings');
            }
            $fields[$field] = $field;
        }
        $fields = array_values($fields);
        sort($fields, SORT_STRING);

        $this->contextFields = $fields;
        $this->maxEntries = $maxEntries;
        $this->extraKey = $extraKey;
    }

    /**
     * @inheritDoc
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $key = $this->buildIdentityKey($record);

        if (isset($this->counts[$key])) {
            $count = $this->counts[$key] + 1;
            // refresh LRU position: move the identity to the end
            unset($this->counts[$key]);
            $this->counts[$key] = $count;
        } else {
            if (\count($this->counts) >= $this->maxEntries) {
                // evict the least recently used identity (first element)
                array_shift($this->counts);
            }
            $count = 1;
            $this->counts[$key] = $count;
        }

        return $record->with(extra: array_merge($record->extra, [$this->extraKey => $count]));
    }

    /**
     * Clears the identity cache. The next occurrence of any identity is
     * numbered 1 again; previous counts are never inherited.
     */
    public function reset(): void
    {
        $this->counts = [];
    }

    /**
     * @return array<string, int> A copy of the current identity => count cache (LRU first)
     */
    public function getCounts(): array
    {
        return $this->counts;
    }

    /**
     * Builds a deterministic identity key from the channel, level, message
     * and the selected scalar context fields. The record datetime is not
     * used. Each value is encoded with an explicit type tag so that e.g.
     * int 1, string "1" and null/missing never collide, and no serialization
     * of unknown values is attempted.
     */
    private function buildIdentityKey(LogRecord $record): string
    {
        $key = 'channel:s'.\strlen($record->channel).':'.$record->channel.';'
            .'level:i'.$record->level->value.';'
            .'message:s'.\strlen($record->message).':'.$record->message.';';

        foreach ($this->contextFields as $field) {
            $key .= 'context['.$field.']:'.$this->encodeContextValue($field, $record->context).';';
        }

        return $key;
    }

    /**
     * @param array<mixed> $context
     */
    private function encodeContextValue(string $field, array $context): string
    {
        // missing field and explicit null share the same "nil" bucket
        if (!\array_key_exists($field, $context) || null === $context[$field]) {
            return 'null';
        }

        $value = $context[$field];

        if (\is_int($value)) {
            return 'int:'.$value;
        }
        if (\is_string($value)) {
            return 'string:'.\strlen($value).':'.$value;
        }
        if (\is_bool($value)) {
            return 'bool:'.($value ? '1' : '0');
        }
        if (\is_float($value)) {
            return 'float:'.\var_export($value, true);
        }

        throw new \InvalidArgumentException(\sprintf(
            'Context field "%s" must contain a scalar or null to be used as duplicate identity, got "%s". Objects, arrays and other non-scalar values are rejected instead of being serialized.',
            $field,
            get_debug_type($value)
        ));
    }
}
