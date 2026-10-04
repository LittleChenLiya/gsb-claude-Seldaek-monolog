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
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\ResettableInterface;
use Monolog\Utils;
use Psr\Log\LogLevel;

/**
 * Persists records in a local segmented, length-delimited and checksummed spool
 * and forwards them in order to a downstream handler on flush().
 *
 * Each segment file contains a sequence of self describing frames:
 *
 *   magic    2 bytes  "MS"
 *   version  1 byte   currently 0x01
 *   length   8 bytes  big endian unsigned payload length
 *   checksum 4 bytes  big endian CRC32 of the payload
 *   payload  length bytes of JSON encoded, normalized LogRecord data
 *
 * The acknowledged position ("segment index + byte offset of the next record
 * to deliver") is stored in a sidecar file that is replaced atomically.
 *
 * Delivery semantics are at-least-once: a record is acknowledged only after the
 * downstream handler returns successfully, so a crash after a successful
 * delivery but before the acknowledgement is replaced causes the record to be
 * delivered again on restart, but no record is ever lost.
 *
 * Recovery on (re)opening:
 *  - records at or past the acknowledged position are replayed from the spool;
 *  - a torn trailing frame in the active (last) segment, the expected
 *    signature of a crash during a write, is truncated away;
 *  - any other damaged frame (bad magic/version/header, length mismatch or
 *    checksum mismatch) aborts recovery with a SpoolCorruptionException that
 *    reports the actual segment index and byte offset of the damage.
 */
class SpoolHandler extends AbstractProcessingHandler
{
    public const ACK_FILENAME = 'ack.json';
    public const SEGMENT_PREFIX = 'spool-';
    public const SEGMENT_SUFFIX = '.log';
    public const SEGMENT_PADDING = 12;
    public const DEFAULT_MAX_SEGMENT_BYTES = 10 * 1024 * 1024;
    public const DEFAULT_MAX_RECORD_BYTES = 16 * 1024 * 1024;

    private const HEADER_PACK = 'a2CJN';
    private const HEADER_UNPACK = 'a2magic/Cversion/Jlength/Nchecksum';
    private const HEADER_SIZE = 15;
    private const MAGIC = 'MS';
    private const VERSION = 1;

    private HandlerInterface $handler;
    private string $spoolDir;
    private string $ackFile;

    private int $maxSegmentBytes;
    private int $maxRecordBytes;
    private bool $flushOnClose;

    private bool $initialized = false;
    private bool $closed = false;

    /** @var resource|null */
    private $stream = null;

    private int $activeSegment = 0;
    private int $activeSize = 0;
    private int $ackSegment = 0;
    private int $ackOffset = 0;

    /**
     * @param string           $spoolDir        Writable directory holding the segment files and the ack file
     * @param HandlerInterface $handler         Downstream handler records are delivered to during flush()
     * @param int              $maxSegmentBytes Bytes a segment may hold before rolling over to a new one (0 disables)
     * @param int              $maxRecordBytes  Maximum encoded record size accepted by the spool
     * @param bool             $flushOnClose    Whether close() flushes pending records downstream
     * @param int|string|Level $level           The minimum logging level at which this handler is triggered
     * @param bool             $bubble          Whether handled messages keep bubbling up the stack
     *
     * @phpstan-param value-of<Level::VALUES>|value-of<Level::NAMES>|Level|LogLevel::* $level
     */
    public function __construct(
        string $spoolDir,
        HandlerInterface $handler,
        int $maxSegmentBytes = self::DEFAULT_MAX_SEGMENT_BYTES,
        int $maxRecordBytes = self::DEFAULT_MAX_RECORD_BYTES,
        bool $flushOnClose = true,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);

        if ($maxSegmentBytes < 0) {
            throw new \InvalidArgumentException('$maxSegmentBytes must be greater than or equal to 0');
        }
        if ($maxRecordBytes <= 0) {
            throw new \InvalidArgumentException('$maxRecordBytes must be greater than 0');
        }

        $this->spoolDir = rtrim($spoolDir, '/\\');
        $this->handler = $handler;
        $this->maxSegmentBytes = $maxSegmentBytes;
        $this->maxRecordBytes = $maxRecordBytes;
        $this->flushOnClose = $flushOnClose;
        $this->ackFile = $this->spoolDir.\DIRECTORY_SEPARATOR.self::ACK_FILENAME;
    }

    /**
     * @inheritDoc
     */
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new NormalizerFormatter();
    }

    /**
     * Delivers pending spooled records to the downstream handler in order and
     * advances the acknowledged position after every successful delivery.
     *
     * Any exception thrown by the downstream handler propagates untouched and
     * stops the flush; the failing record stays unacknowledged and is delivered
     * again on the next flush or after a restart.
     *
     * @return int Number of records successfully delivered during this call
     */
    public function flush(): int
    {
        $this->initialize();

        $delivered = 0;
        $segment = $this->ackSegment;
        $offset = $this->ackOffset;
        $handle = null;

        try {
            while ($segment <= $this->activeSegment) {
                if (null === $handle) {
                    $path = $this->segmentPath($segment);
                    if (!is_file($path)) {
                        throw new SpoolException(
                            'Missing spool segment file '.$path.' while flushing from segment '.$segment
                        );
                    }
                    $handle = $this->openSegmentForRead($segment);
                }

                [$frameSize, $record] = $this->readRecordAt($handle, $segment, $offset);

                if (null === $record) {
                    // Clean end of this segment.
                    if ($segment < $this->activeSegment) {
                        // Advance the ack to the start of the next segment
                        // *before* deleting the file: a crash between the two
                        // steps only leaves an unneeded segment behind, while
                        // deleting first would make the ack point at a
                        // vanished segment on restart.
                        fclose($handle);
                        $handle = null;
                        $this->persistAck($segment + 1, 0);
                        $this->discardSegmentsBefore($segment + 1);
                        ++$segment;
                        $offset = 0;
                        continue;
                    }

                    // Active segment fully delivered.
                    break;
                }

                // At-least-once delivery boundary: a crash between this call
                // returning and persistAck() re-delivers this record on restart.
                $this->handler->handle($record);

                $offset += $frameSize;
                $this->persistAck($segment, $offset);
                ++$delivered;
            }
        } finally {
            if (null !== $handle) {
                fclose($handle);
            }
        }

        return $delivered;
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        try {
            if ($this->flushOnClose) {
                $this->flush();
            }
        } finally {
            if (null !== $this->stream) {
                fclose($this->stream);
                $this->stream = null;
            }
            $this->initialized = false;
        }

        $this->handler->close();
    }

    /**
     * @inheritDoc
     */
    public function reset(): void
    {
        $this->flush();

        parent::reset();

        $this->resetProcessors();

        if ($this->handler instanceof ResettableInterface) {
            $this->handler->reset();
        }
    }

    public function getAcknowledgedSegment(): int
    {
        $this->initialize();

        return $this->ackSegment;
    }

    public function getAcknowledgedOffset(): int
    {
        $this->initialize();

        return $this->ackOffset;
    }

    public function getActiveSegment(): int
    {
        $this->initialize();

        return $this->activeSegment;
    }

    /**
     * @inheritDoc
     */
    protected function write(LogRecord $record): void
    {
        $this->initialize();

        $formatted = $record->formatted;
        if (!is_array($formatted)) {
            // The default NormalizerFormatter always returns an array; a
            // formatter returning anything else can not be rehydrated safely.
            throw new SpoolException('The configured formatter must return an array for the SpoolHandler');
        }

        $json = $this->jsonEncode($formatted);
        $length = strlen($json);
        if ($length > $this->maxRecordBytes) {
            throw new SpoolException(
                sprintf(
                    'Encoded record is %d bytes long which exceeds the %d bytes spool limit',
                    $length,
                    $this->maxRecordBytes,
                )
            );
        }

        $frame = $this->encodeFrame($json);

        if ($this->shouldRollOver(strlen($frame))) {
            $this->rollOver();
        }

        if (!$this->writeAll($frame) || fflush($this->stream()) !== true) {
            // The on-disk position is unreliable after a failed write: close down
            // so that reopening validates and truncates the partial tail.
            $this->abortActiveStream();

            throw new SpoolException(
                'Failed writing record to spool segment '.$this->segmentPath($this->activeSegment)
            );
        }

        $this->activeSize += strlen($frame);
    }

    /**
     * Opens (creating it if needed) the active segment stream. Overriding this
     * method is the supported extension point to inject failing streams.
     *
     * @return resource
     */
    protected function openActiveSegment(string $path)
    {
        $this->ensureDirectory(dirname($path));

        $stream = @fopen($path, 'cb+');
        if (false === $stream) {
            throw new SpoolException('Could not open spool segment file for writing: '.$path);
        }
        if (fseek($stream, 0, SEEK_END) !== 0) {
            throw new SpoolException('Could not seek to the end of spool segment file: '.$path);
        }

        return $stream;
    }

    /**
     * @return resource
     */
    private function openSegmentForRead(int $segment)
    {
        $handle = @fopen($this->segmentPath($segment), 'rb');
        if (false === $handle) {
            throw new SpoolException('Could not open spool segment file for reading: '.$this->segmentPath($segment));
        }

        return $handle;
    }

    /**
     * @return resource
     */
    private function openSegmentForWrite(int $segment)
    {
        $handle = @fopen($this->segmentPath($segment), 'rb+');
        if (false === $handle) {
            throw new SpoolException('Could not open spool segment file for repair: '.$this->segmentPath($segment));
        }

        return $handle;
    }

    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->closed = false;
        $this->ensureDirectory($this->spoolDir);

        [$this->ackSegment, $this->ackOffset] = $this->readAck();
        $segments = $this->scanSegments();

        if ($segments === []) {
            if ($this->ackSegment !== 0 || $this->ackOffset !== 0) {
                throw new SpoolException(
                    sprintf(
                        'Ack points at segment %d offset %d but the spool directory contains no segments',
                        $this->ackSegment,
                        $this->ackOffset,
                    )
                );
            }
            $this->activeSegment = 0;
            $this->activeSize = 0;
        } else {
            $this->activeSegment = (int) end($segments);
            $lowest = (int) reset($segments);

            if ($this->ackSegment < $lowest || $this->ackSegment > $this->activeSegment) {
                throw new SpoolException(
                    sprintf(
                        'Ack points at segment %d but available segments range from %d to %d',
                        $this->ackSegment,
                        $lowest,
                        $this->activeSegment,
                    )
                );
            }

            // Segments before the ack can never be delivered anymore.
            $this->discardSegmentsBefore($this->ackSegment);
            $segments = $this->scanSegments();
            $lowest = (int) reset($segments);

            // A hole between segment indexes would silently skip records.
            $expected = $lowest;
            foreach ($segments as $present) {
                if ($present !== $expected) {
                    throw new SpoolException(
                        sprintf('Missing spool segment file %s while scanning the spool', $this->segmentPath($expected))
                    );
                }
                ++$expected;
            }

            // Validate every segment starting at the acknowledged one.
            foreach ($segments as $segment) {
                $this->validateSegment(
                    $segment,
                    $segment === $this->activeSegment,
                    $this->ackSegment,
                    $this->ackOffset,
                );
            }
        }

        $path = $this->segmentPath($this->activeSegment);
        $this->stream = $this->openActiveSegment($path);
        $this->activeSize = $this->segmentLogicalSize($this->activeSegment);

        $this->initialized = true;
    }

    /**
     * Validates the frames of one segment and truncates a torn tail on the
     * active (last) segment.
     */
    private function validateSegment(int $segment, bool $active, int $fromSegment, int $fromOffset): void
    {
        $handle = $this->openSegmentForRead($segment);
        $fileSize = $this->streamSize($handle);
        $offset = 0;
        $validEnd = 0;
        /** @var int[] byte offsets at which a valid frame starts or ends */
        $boundaries = [0];

        try {
            while ($offset < $fileSize) {
                $start = $offset;

                $headerRaw = $this->readBytes($handle, self::HEADER_SIZE, $offset, $fileSize);
                if (null === $headerRaw) {
                    $this->repairTail($handle, $segment, $validEnd, $active);
                    $handle = null;
                    $fileSize = $validEnd;
                    break;
                }

                $header = unpack(self::HEADER_UNPACK, $headerRaw);
                if (false === $header || self::MAGIC !== $header['magic'] || self::VERSION !== $header['version']) {
                    throw new SpoolCorruptionException('Invalid spool frame header', $segment, $start);
                }

                $length = (int) $header['length'];
                if ($length > $this->maxRecordBytes) {
                    throw new SpoolCorruptionException(
                        sprintf('Spool frame declares an invalid payload length of %d bytes', $length),
                        $segment,
                        $start,
                    );
                }

                $payload = $this->readBytes($handle, $length, $offset + self::HEADER_SIZE, $fileSize);
                if (null === $payload) {
                    $this->repairTail($handle, $segment, $validEnd, $active);
                    $handle = null;
                    $fileSize = $validEnd;
                    break;
                }

                if (!$this->checksumMatches($payload, (int) $header['checksum'])) {
                    throw new SpoolCorruptionException('Spool frame checksum mismatch', $segment, $start);
                }

                // Make sure the payload itself is decodable before delivery.
                $this->decodePayload($payload, $segment, $start);

                $offset = $start + self::HEADER_SIZE + $length;
                $validEnd = $offset;
                $boundaries[] = $offset;
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($segment === $fromSegment && !in_array($fromOffset, $boundaries, true)) {
            throw new SpoolException(
                sprintf('Ack offset %d does not point at a frame boundary in segment %d', $fromOffset, $segment)
            );
        }
    }

    /**
     * Truncates the active segment back to the last complete frame.
     *
     * @param resource $handle read handle, closed before reopening for repair
     */
    private function repairTail($handle, int $segment, int $validEnd, bool $active): void
    {
        if (!$active) {
            throw new SpoolCorruptionException(
                'Truncated frame found inside a sealed (non-last) segment',
                $segment,
                $validEnd,
            );
        }

        // Reopen writable: on Windows ftruncate() does not work on a read handle.
        fclose($handle);
        $writeHandle = $this->openSegmentForWrite($segment);

        try {
            if (ftruncate($writeHandle, max(0, $validEnd)) !== true) {
                throw new SpoolException(
                    'Could not truncate torn spool tail of '.$this->segmentPath($segment).' at offset '.$validEnd
                );
            }
            fflush($writeHandle);
        } finally {
            fclose($writeHandle);
        }

        clearstatcache(true, $this->segmentPath($segment));
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function readAck(): array
    {
        if (!is_file($this->ackFile)) {
            return [0, 0];
        }

        $raw = @file_get_contents($this->ackFile);
        if (false === $raw) {
            throw new SpoolException('Could not read spool ack file '.$this->ackFile);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SpoolException('Spool ack file is not valid JSON: '.$this->ackFile, 0, $e);
        }

        if (!is_array($data)
            || !isset($data['segment'], $data['offset'])
            || !is_int($data['segment'])
            || !is_int($data['offset'])
            || $data['segment'] < 0
            || $data['offset'] < 0
        ) {
            throw new SpoolException('Spool ack file has an invalid structure: '.$this->ackFile);
        }

        return [$data['segment'], $data['offset']];
    }

    /**
     * Atomically persists the acknowledged position. Made protected so failure
     * injection (crash before acknowledgement) can be tested deterministically.
     *
     * The in-memory position is only advanced once the file replacement has
     * succeeded, so a failed write keeps flush() redelivering the record.
     */
    protected function persistAck(int $segment, int $offset): void
    {
        $json = json_encode(
            ['segment' => $segment, 'offset' => $offset],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (false === $json) {
            throw new SpoolException('Could not encode spool ack data: '.json_last_error_msg());
        }

        $tmp = $this->ackFile.'.tmp-'.getmypid().'-'.bin2hex(random_bytes(4));
        $this->ensureDirectory($this->spoolDir);

        if (false === @file_put_contents($tmp, $json, LOCK_EX)) {
            throw new SpoolException('Could not write temporary spool ack file '.$tmp);
        }

        // Atomic replacement. On POSIX rename() atomically replaces the target.
        // On Windows rename() fails while the destination exists, so unlink it
        // first: that tiny window can at worst turn a crash into "no ack"
        // (re-delivery on restart), never into data loss. This stays inside the
        // documented at-least-once delivery bounds.
        if (!@rename($tmp, $this->ackFile)) {
            if ('\\' === \DIRECTORY_SEPARATOR && is_file($this->ackFile)) {
                @unlink($this->ackFile);
            }
            if (!@rename($tmp, $this->ackFile)) {
                @unlink($tmp);

                throw new SpoolException('Could not atomically replace spool ack file '.$this->ackFile);
            }
        }

        if ('\\' === \DIRECTORY_SEPARATOR && is_file($tmp)) {
            @unlink($tmp);
        }

        $this->ackSegment = $segment;
        $this->ackOffset = $offset;
    }

    /**
     * @return int[] sorted unique segment indexes present on disk
     */
    private function scanSegments(): array
    {
        $segments = [];
        $handle = @opendir($this->spoolDir);
        if (false === $handle) {
            throw new SpoolException('Could not open spool directory '.$this->spoolDir);
        }

        try {
            while (false !== ($entry = readdir($handle))) {
                if (preg_match(
                    '/^'.preg_quote(self::SEGMENT_PREFIX, '/').'(\d+)'.preg_quote(self::SEGMENT_SUFFIX, '/').'$/',
                    $entry,
                    $m
                )) {
                    $segments[(int) $m[1]] = true;
                }
            }
        } finally {
            closedir($handle);
        }

        $segments = array_keys($segments);
        sort($segments);

        return $segments;
    }

    private function discardSegmentsBefore(int $segment): void
    {
        foreach ($this->scanSegments() as $present) {
            if ($present < $segment) {
                @unlink($this->segmentPath($present));
            }
        }
    }

    private function segmentPath(int $segment): string
    {
        return $this->spoolDir
            .\DIRECTORY_SEPARATOR
            .self::SEGMENT_PREFIX
            .str_pad((string) $segment, self::SEGMENT_PADDING, '0', STR_PAD_LEFT)
            .self::SEGMENT_SUFFIX;
    }

    private function segmentLogicalSize(int $segment): int
    {
        clearstatcache(true, $this->segmentPath($segment));
        $size = @filesize($this->segmentPath($segment));

        return false === $size ? 0 : (int) $size;
    }

    private function shouldRollOver(int $nextFrameSize): bool
    {
        if ($this->maxSegmentBytes <= 0) {
            return false;
        }

        return $this->activeSize > 0 && $this->activeSize + $nextFrameSize > $this->maxSegmentBytes;
    }

    private function rollOver(): void
    {
        if (null !== $this->stream) {
            fclose($this->stream);
            $this->stream = null;
        }

        ++$this->activeSegment;
        $path = $this->segmentPath($this->activeSegment);
        $this->stream = $this->openActiveSegment($path);
        $this->activeSize = 0;
    }

    private function abortActiveStream(): void
    {
        if (null !== $this->stream) {
            @fclose($this->stream);
            $this->stream = null;
        }
        $this->initialized = false;
    }

    /**
     * @return resource
     */
    private function stream()
    {
        if (null === $this->stream) {
            throw new SpoolException('Spool segment stream is not open');
        }

        return $this->stream;
    }

    private function writeAll(string $data): bool
    {
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $result = @fwrite($this->stream(), substr($data, $written));
            if (false === $result || 0 === $result) {
                return false;
            }
            $written += $result;
        }

        return $written === $length;
    }

    private function encodeFrame(string $payload): string
    {
        return pack(
            self::HEADER_PACK,
            self::MAGIC,
            self::VERSION,
            strlen($payload),
            $this->checksum($payload),
        ).$payload;
    }

    private function checksum(string $payload): int
    {
        $value = crc32($payload);

        return $value < 0 ? $value + 0x100000000 : $value;
    }

    private function checksumMatches(string $payload, int $expected): bool
    {
        return $this->checksum($payload) === $expected;
    }

    /**
     * Reads one record at an exact offset inside a segment file.
     *
     * @param resource $handle
     * @return array{0:int, 1:LogRecord|null} consumed frame size and decoded record (null at clean segment end)
     */
    private function readRecordAt($handle, int $segment, int $offset): array
    {
        $fileSize = $this->streamSize($handle);
        if ($offset > $fileSize) {
            throw new SpoolCorruptionException('Read offset past end of segment', $segment, $offset);
        }
        if ($offset === $fileSize) {
            return [0, null];
        }

        fseek($handle, $offset);
        $headerRaw = $this->readExact($handle, self::HEADER_SIZE);
        if (null === $headerRaw) {
            // Reopening truncates a genuinely torn active tail, so reaching a
            // short frame here means on-disk corruption.
            throw new SpoolCorruptionException('Truncated spool frame header', $segment, $offset);
        }

        $header = unpack(self::HEADER_UNPACK, $headerRaw);
        if (false === $header || self::MAGIC !== $header['magic'] || self::VERSION !== $header['version']) {
            throw new SpoolCorruptionException('Invalid spool frame header', $segment, $offset);
        }

        $length = (int) $header['length'];
        if ($length > $this->maxRecordBytes) {
            throw new SpoolCorruptionException(
                sprintf('Spool frame declares an invalid payload length of %d bytes', $length),
                $segment,
                $offset,
            );
        }

        $payload = $this->readExact($handle, $length);
        if (null === $payload) {
            throw new SpoolCorruptionException('Truncated spool frame payload', $segment, $offset);
        }

        if (!$this->checksumMatches($payload, (int) $header['checksum'])) {
            throw new SpoolCorruptionException('Spool frame checksum mismatch', $segment, $offset);
        }

        return [self::HEADER_SIZE + $length, $this->decodePayload($payload, $segment, $offset)];
    }

    /**
     * Reads exactly $length bytes starting at $offset or returns null if the
     * segment ends before the full read (a potentially torn tail).
     *
     * @param resource $handle
     */
    private function readBytes($handle, int $length, int $offset, int $fileSize): ?string
    {
        if ($offset + $length > $fileSize) {
            return null;
        }

        if (fseek($handle, $offset) !== 0) {
            return null;
        }

        return $this->readExact($handle, $length);
    }

    /**
     * @param resource $handle
     */
    private function streamSize($handle): int
    {
        $stat = fstat($handle);
        if (false === $stat) {
            throw new SpoolException('Could not stat spool segment stream');
        }

        return (int) $stat['size'];
    }

    /**
     * @param resource $handle
     */
    private function readExact($handle, int $length): ?string
    {
        if ($length <= 0) {
            return '';
        }

        $data = '';
        while (strlen($data) < $length) {
            $remaining = $length - strlen($data);
            if ($remaining < 1) {
                break;
            }
            $chunk = fread($handle, $remaining);
            if (false === $chunk || ($chunk === '' && feof($handle))) {
                return null;
            }
            $data .= $chunk;
        }

        return strlen($data) === $length ? $data : null;
    }

    private function decodePayload(string $payload, int $segment, int $offset): LogRecord
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SpoolCorruptionException('Spool frame payload is not valid JSON', $segment, $offset, $e);
        }

        foreach (['message', 'context', 'level', 'level_name', 'channel', 'datetime', 'extra'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new SpoolCorruptionException(
                    'Spool frame payload is missing record field "'.$key.'"',
                    $segment,
                    $offset,
                );
            }
        }

        if (!is_string($data['message'])
            || !is_array($data['context'])
            || !is_array($data['extra'])
            || !is_string($data['channel'])
            || !is_int($data['level'])
            || !is_string($data['datetime'])
        ) {
            throw new SpoolCorruptionException('Spool frame payload contains invalid record fields', $segment, $offset);
        }

        try {
            $datetime = new \DateTimeImmutable($data['datetime']);
        } catch (\Exception $e) {
            throw new SpoolCorruptionException(
                'Spool frame payload contains an invalid record datetime',
                $segment,
                $offset,
                $e,
            );
        }

        if (!in_array($data['level'], Level::VALUES, true)) {
            throw new SpoolCorruptionException(
                'Spool frame payload contains an unknown log level '.$data['level'],
                $segment,
                $offset,
            );
        }

        return new LogRecord(
            datetime: $datetime,
            channel: $data['channel'],
            level: Level::fromValue($data['level']),
            message: $data['message'],
            context: $data['context'],
            extra: $data['extra'],
        );
    }

    /**
     * @param array<mixed> $data
     */
    private function jsonEncode(array $data): string
    {
        try {
            return json_encode(
                $data,
                JSON_THROW_ON_ERROR | Utils::DEFAULT_JSON_FLAGS
            );
        } catch (\JsonException $e) {
            throw new SpoolException(
                'Could not normalize and encode log record for spool: '.$e->getMessage(),
                0,
                $e,
            );
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new SpoolException('Could not create spool directory '.$directory);
        }
    }
}
