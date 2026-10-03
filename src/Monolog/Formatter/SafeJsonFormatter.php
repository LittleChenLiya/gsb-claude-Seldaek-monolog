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
use Monolog\Utils;
use ReflectionReference;

/**
 * Encodes records as JSON while guaranteeing that no code embedded in the
 * record data (context, extra, message, ...) is ever executed while formatting.
 *
 * Unlike JsonFormatter, this formatter never calls __toString(), jsonSerialize()
 * or any getter/property accessor on objects found in the record data. Only
 * arrays and scalars are traversed; objects are reported as a type marker and
 * resources as a resource marker. Array recursion is bounded by depth, item
 * count and circular-reference guards, each with its own truncation report.
 *
 * The record datetime, level and channel are always reported with their real
 * values. Strings containing bytes that are not valid UTF-8 are not silently
 * substituted or dropped: each invalid byte is rendered as an explicit \xNN
 * escape sequence before encoding, so the whole record always encodes.
 */
class SafeJsonFormatter extends JsonFormatter
{
    /**
     * Truncation reason used when an array nested deeper than maxNormalizeDepth is encountered.
     */
    public const REASON_DEPTH = 'max depth exceeded';

    /**
     * Truncation reason used when an array holds more than maxNormalizeItemCount entries.
     */
    public const REASON_ITEMS = 'max item count exceeded';

    /**
     * Truncation reason used when a circular array reference is detected.
     */
    public const REASON_CIRCULAR = 'circular reference detected';

    /**
     * @inheritDoc
     */
    protected function normalizeRecord(LogRecord $record): array
    {
        $normalized = [
            'message' => $this->normalize($record->message),
            'context' => $this->normalize($record->context, 1),
            'level' => $record->level->value,
            'level_name' => $record->level->getName(),
            'channel' => $record->channel,
            'datetime' => $record->datetime->format($this->dateFormat),
            'extra' => $this->normalize($record->extra, 1),
        ];

        if ($normalized['context'] === []) {
            if ($this->ignoreEmptyContextAndExtra) {
                unset($normalized['context']);
            } else {
                $normalized['context'] = new \stdClass();
            }
        }
        if ($normalized['extra'] === []) {
            if ($this->ignoreEmptyContextAndExtra) {
                unset($normalized['extra']);
            } else {
                $normalized['extra'] = new \stdClass();
            }
        }

        return $normalized;
    }

    /**
     * Safely normalizes an arbitrary value.
     *
     * No method is ever called on objects: arrays and scalars are the only
     * traversable structures, objects are turned into a type marker, resources
     * into a resource marker.
     *
     * @param array<string, true> $referenceStack
     * @return null|scalar|array<mixed[]|scalar|null>
     */
    protected function normalize(mixed $data, int $depth = 0, array &$referenceStack = []): mixed
    {
        if (null === $data || \is_scalar($data)) {
            return $this->normalizeScalar($data);
        }

        if (\is_array($data)) {
            if ($depth > $this->maxNormalizeDepth) {
                return $this->truncated(self::REASON_DEPTH, $this->maxNormalizeDepth);
            }

            $normalized = [];

            $count = 1;
            foreach ($data as $key => $value) {
                if ($count++ > $this->maxNormalizeItemCount) {
                    $normalized['...'] = $this->truncated(self::REASON_ITEMS, $this->maxNormalizeItemCount, \count($data));
                    break;
                }

                if (\is_array($value)) {
                    $reference = ReflectionReference::fromArrayElement($data, $key);
                    if ($reference !== null) {
                        $id = $reference->getId();
                        if (isset($referenceStack[$id])) {
                            $normalized[$key] = $this->truncated(self::REASON_CIRCULAR);
                            continue;
                        }
                        $referenceStack[$id] = true;
                        try {
                            $normalized[$key] = $this->normalize($value, $depth + 1, $referenceStack);
                        } finally {
                            unset($referenceStack[$id]);
                        }
                    } else {
                        $normalized[$key] = $this->normalize($value, $depth + 1, $referenceStack);
                    }
                } else {
                    $normalized[$key] = $this->normalize($value, $depth + 1, $referenceStack);
                }
            }

            return $normalized;
        }

        if (\is_object($data)) {
            return \sprintf('[object(%s)]', Utils::getClass($data));
        }

        if (\is_resource($data)) {
            return \sprintf('[resource(%s)]', \get_resource_type($data));
        }

        return \sprintf('[unknown(%s)]', \gettype($data));
    }

    /**
     * Normalizes a scalar value, rendering non UTF-8 strings as explicit \xNN byte escapes.
     *
     * @return null|int|float|string|bool
     */
    private function normalizeScalar(mixed $data): mixed
    {
        if (\is_float($data)) {
            if (\is_infinite($data)) {
                return ($data > 0 ? '' : '-') . 'INF';
            }
            if (\is_nan($data)) {
                return 'NaN';
            }
        }

        if (\is_string($data)) {
            return $this->encodeUtf8Safely($data);
        }

        return $data;
    }

    /**
     * Renders a string so it only contains valid UTF-8.
     *
     * Valid UTF-8 byte sequences are kept verbatim, each invalid byte is
     * replaced by an explicit \xNN escape, so the string can be JSON encoded
     * without substituting or dropping any byte.
     */
    private function encodeUtf8Safely(string $string): string
    {
        $out = '';
        $len = \strlen($string);
        $clean = true;

        for ($i = 0; $i < $len;) {
            $byte = \ord($string[$i]);

            if ($byte < 0x80) {
                $out .= $string[$i];
                $i++;
                continue;
            }

            $sequenceLength = match (true) {
                $byte >= 0xC2 && $byte <= 0xDF => 2,
                $byte >= 0xE0 && $byte <= 0xEF => 3,
                $byte >= 0xF0 && $byte <= 0xF4 => 4,
                default => null,
            };

            if ($sequenceLength !== null && $i + $sequenceLength <= $len) {
                $sequence = \substr($string, $i, $sequenceLength);
                if ($this->isValidUtf8Sequence($sequence)) {
                    $out .= $sequence;
                    $i += $sequenceLength;
                    continue;
                }
            }

            $out .= \sprintf('\\x%02X', $byte);
            $clean = false;
            $i++;
        }

        return $clean ? $string : $out;
    }

    /**
     * Validates a single UTF-8 sequence (1 to 4 bytes).
     */
    private function isValidUtf8Sequence(string $sequence): bool
    {
        $length = \strlen($sequence);

        if ($length === 1) {
            return \ord($sequence[0]) < 0x80;
        }

        for ($i = 1; $i < $length; $i++) {
            $byte = \ord($sequence[$i]);
            if ($byte < 0x80 || $byte > 0xBF) {
                return false;
            }
        }

        $leading = \ord($sequence[0]);

        if ($length === 2) {
            $codepoint = (($leading & 0x1F) << 6) | (\ord($sequence[1]) & 0x3F);

            return $codepoint >= 0x80;
        }

        if ($length === 3) {
            $codepoint = (($leading & 0x0F) << 12) | ((\ord($sequence[1]) & 0x3F) << 6) | (\ord($sequence[2]) & 0x3F);

            return $codepoint >= 0x800 && ($codepoint < 0xD800 || $codepoint > 0xDFFF);
        }

        $codepoint = (($leading & 0x07) << 18) | ((\ord($sequence[1]) & 0x3F) << 12) | ((\ord($sequence[2]) & 0x3F) << 6) | (\ord($sequence[3]) & 0x3F);

        return $codepoint >= 0x10000 && $codepoint <= 0x10FFFF;
    }

    /**
     * Builds the truncation marker for a given reason.
     */
    private function truncated(string $reason, int $limit = 0, ?int $total = null): string
    {
        return match ($reason) {
            self::REASON_DEPTH => \sprintf('Over %d levels deep, aborting normalization', $limit),
            self::REASON_ITEMS => \sprintf('Over %d items (%d total), aborting normalization', $limit, $total ?? 0),
            self::REASON_CIRCULAR => 'Circular reference detected, aborting normalization',
            default => throw new \InvalidArgumentException('Unknown truncation reason: '.$reason),
        };
    }
}
