<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Formatter;

use Monolog\LogRecord;

/**
 * Encodes log records as JSON without ever invoking userland object behavior.
 *
 * Unlike JsonFormatter/NormalizerFormatter this formatter never calls
 * __toString(), jsonSerialize(), getters or any other object method while
 * normalizing the record context/extra. Only arrays and scalars are
 * traversed recursively:
 *
 *  - objects are replaced by an explicit type marker: "[object:Class\Name]"
 *  - resources are replaced by a dedicated marker: "[resource:stream]" /
 *    "[resource:closed]"
 *  - non-UTF-8 strings are kept, but every invalid byte is rendered as an
 *    explicit \xNN escape so the record is never dropped nor silently
 *    mangled
 *  - circular array references, the depth limit and the per-array item
 *    limit each produce a deterministic truncation report holding a
 *    distinct machine-readable "reason" (self::REASON_*)
 *
 * The record's own datetime, level and channel are always emitted with
 * their real values.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class SafeJsonFormatter implements FormatterInterface
{
    public const BATCH_MODE_JSON = 1;
    public const BATCH_MODE_NEWLINES = 2;

    /** Truncation reason: nesting went deeper than the configured max depth */
    public const REASON_MAX_DEPTH = 'max_depth';
    /** Truncation reason: an array held more items than the configured max item count */
    public const REASON_MAX_ITEMS = 'max_items';
    /** Truncation reason: a circular array reference was detected */
    public const REASON_RECURSION = 'recursion';

    public const SIMPLE_DATE = "Y-m-d\TH:i:sP";

    /**
     * Marker key injected into arrays while they are being normalized to
     * detect circular references. It is removed again before returning.
     */
    private const RECURSION_GUARD_KEY = "\0monolog.safe_json.recursion_guard\0";

    /** @var self::BATCH_MODE_* */
    protected int $batchMode;

    protected bool $appendNewline;

    protected int $maxDepth;

    protected int $maxItems;

    protected string $dateFormat;

    /**
     * @param self::BATCH_MODE_* $batchMode
     */
    public function __construct(
        int $batchMode = self::BATCH_MODE_JSON,
        bool $appendNewline = true,
        int $maxDepth = 9,
        int $maxItems = 1000,
        ?string $dateFormat = null,
    ) {
        if ($maxDepth < 0) {
            throw new \InvalidArgumentException('maxDepth must be >= 0');
        }
        if ($maxItems < 1) {
            throw new \InvalidArgumentException('maxItems must be >= 1');
        }

        $this->batchMode = $batchMode;
        $this->appendNewline = $appendNewline;
        $this->maxDepth = $maxDepth;
        $this->maxItems = $maxItems;
        $this->dateFormat = $dateFormat ?? self::SIMPLE_DATE;
    }

    /**
     * @return self::BATCH_MODE_*
     */
    public function getBatchMode(): int
    {
        return $this->batchMode;
    }

    public function isAppendingNewlines(): bool
    {
        return $this->appendNewline;
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    public function getMaxItems(): int
    {
        return $this->maxItems;
    }

    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    /**
     * Formats a record as a JSON string.
     *
     * The datetime, level, level_name and channel fields always carry the
     * real values of the LogRecord; context and extra are normalized safely.
     */
    public function format(LogRecord $record): string
    {
        return $this->encodeJson($this->normalizeRecord($record)) . ($this->appendNewline ? "\n" : '');
    }

    /**
     * @param LogRecord[] $records
     */
    public function formatBatch(array $records): string
    {
        return match ($this->batchMode) {
            self::BATCH_MODE_NEWLINES => $this->formatBatchNewlines($records),
            default => $this->formatBatchJson($records),
        };
    }

    /**
     * Normalizes a single arbitrary value using the safe rules of this formatter.
     */
    public function normalizeValue(mixed $data): mixed
    {
        return $this->normalize($data, 0);
    }

    /**
     * @return array{message: string, context: mixed, level: int, level_name: string, channel: string, datetime: string, extra: mixed}
     */
    protected function normalizeRecord(LogRecord $record): array
    {
        $context = $record->context;
        $extra = $record->extra;

        return [
            'message' => $this->sanitizeString($record->message),
            'context' => $this->normalize($context, 0),
            'level' => $record->level->value,
            'level_name' => $record->level->getName(),
            'channel' => $this->sanitizeString($record->channel),
            'datetime' => $record->datetime->format($this->dateFormat),
            'extra' => $this->normalize($extra, 0),
        ];
    }

    /**
     * Recursively normalizes arrays and scalars only.
     *
     * Arrays are passed by reference so that circular references can be
     * detected with a guard marker; the marker is always removed again
     * before this method returns.
     *
     * @return null|scalar|array<mixed>
     */
    private function normalize(mixed &$data, int $depth): mixed
    {
        if (null === $data || \is_scalar($data)) {
            if (\is_string($data)) {
                return $this->sanitizeString($data);
            }
            if (\is_float($data)) {
                if (is_infinite($data)) {
                    return ($data > 0 ? '' : '-') . 'INF';
                }
                if (is_nan($data)) {
                    return 'NaN';
                }
            }

            return $data;
        }

        if (\is_array($data)) {
            if ($depth > $this->maxDepth) {
                return $this->truncation(self::REASON_MAX_DEPTH, [
                    'max_depth' => $this->maxDepth,
                ]);
            }

            if (\array_key_exists(self::RECURSION_GUARD_KEY, $data)) {
                return $this->truncation(self::REASON_RECURSION, [
                    'detail' => 'circular array reference detected',
                ]);
            }

            $data[self::RECURSION_GUARD_KEY] = true;
            try {
                $normalized = [];
                $count = 0;
                $total = \count($data) - 1; // exclude the guard marker itself
                foreach ($data as $key => &$value) {
                    if (self::RECURSION_GUARD_KEY === $key) {
                        continue;
                    }
                    if ($count >= $this->maxItems) {
                        $normalized['...'] = $this->truncation(self::REASON_MAX_ITEMS, [
                            'max_items' => $this->maxItems,
                            'total_items' => $total,
                        ]);
                        break;
                    }
                    $count++;

                    $normalized[\is_string($key) ? $this->sanitizeString($key) : $key] = $this->normalize($value, $depth + 1);
                }
                unset($value);

                return $normalized;
            } finally {
                unset($data[self::RECURSION_GUARD_KEY]);
            }
        }

        if (\is_object($data)) {
            // Explicit type marker only: no __toString, no jsonSerialize, no getters.
            return '[object:' . $data::class . ']';
        }

        if (\is_resource($data)) {
            return '[resource:' . get_resource_type($data) . ']';
        }

        // closed resources and anything else exotic
        $debugType = get_debug_type($data);
        if ('resource (closed)' === $debugType) {
            return '[resource:closed]';
        }

        return '[' . str_replace(' ', ':', $debugType) . ']';
    }

    /**
     * Builds a deterministic truncation report with a machine-readable reason.
     *
     * @param self::REASON_* $reason
     * @param array<string, int|string> $details
     * @return array{__safe_truncated: true, reason: self::REASON_*}
     */
    private function truncation(string $reason, array $details = []): array
    {
        return ['__safe_truncated' => true, 'reason' => $reason] + $details;
    }

    /**
     * Returns the string unchanged when it is valid UTF-8, otherwise renders
     * every invalid byte as an explicit \xNN escape while keeping all valid
     * sequences (including multi-byte ones such as Chinese characters).
     */
    private function sanitizeString(string $str): string
    {
        if (1 === preg_match('//u', $str)) {
            return $str;
        }

        $out = '';
        $len = \strlen($str);
        for ($i = 0; $i < $len; ) {
            $byte = \ord($str[$i]);
            $seqLen = match (true) {
                $byte < 0x80 => 1,
                $byte >= 0xC2 && $byte <= 0xDF => 2,
                $byte >= 0xE0 && $byte <= 0xEF => 3,
                $byte >= 0xF0 && $byte <= 0xF4 => 4,
                default => 0,
            };
            if (1 === $seqLen) {
                $out .= $str[$i];
                $i++;
                continue;
            }
            if ($seqLen > 1 && $i + $seqLen <= $len) {
                $seq = substr($str, $i, $seqLen);
                if (1 === preg_match('//u', $seq)) {
                    $out .= $seq;
                    $i += $seqLen;
                    continue;
                }
            }
            $out .= sprintf('\x%02X', $byte);
            $i++;
        }

        return $out;
    }

    /**
     * @param LogRecord[] $records
     */
    protected function formatBatchJson(array $records): string
    {
        $formatted = array_map(fn (LogRecord $record) => $this->normalizeRecord($record), $records);

        return $this->encodeJson($formatted);
    }

    /**
     * @param LogRecord[] $records
     */
    protected function formatBatchNewlines(array $records): string
    {
        $oldNewline = $this->appendNewline;
        $this->appendNewline = false;
        $formatted = array_map(fn (LogRecord $record) => $this->format($record), $records);
        $this->appendNewline = $oldNewline;

        return implode("\n", $formatted);
    }

    /**
     * @param mixed $data normalized data containing only scalars, arrays and null
     */
    private function encodeJson(mixed $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        if (false === $json) {
            // By construction the normalized data cannot fail to encode; if it
            // ever does, fail loudly instead of silently dropping the record.
            throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
        }

        return $json;
    }
}
