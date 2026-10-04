<?php declare(strict_types=1);

namespace Monolog\Handler;

use Monolog\Level;
use Monolog\LogRecord;
use Monolog\ResettableInterface;

class SpoolHandler extends AbstractProcessingHandler
{
    private const FRAME_OVERHEAD = 8;
    private const SEGMENT_PREFIX = 'spool-';
    private const SEGMENT_SUFFIX = '.log';
    private const CONFIRM_FILE = 'spool.confirm';
    private const CONFIRM_TMP = 'spool.confirm.tmp';

    protected HandlerInterface $handler;
    protected string $spoolDir;
    protected int $segmentLimit;
    protected int $maxSegments;

    protected $writeHandle = null;
    protected int $writeSegment = 0;
    protected int $writeOffset = 0;

    protected int $confirmSegment = 0;
    protected int $confirmOffset = 0;

    protected bool $initialized = false;
    protected bool $closed = false;

    public function __construct(
        HandlerInterface $handler,
        string $spoolDir,
        int $segmentLimit = 1048576,
        int $maxSegments = 0,
        int|string|Level $level = Level::Debug,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
        $this->handler = $handler;
        $this->spoolDir = $spoolDir;
        $this->segmentLimit = $segmentLimit;
        $this->maxSegments = $maxSegments;
    }

    public function handle(LogRecord $record): bool
    {
        if (!$this->initialized) {
            register_shutdown_function([$this, 'close']);
            $this->initialized = true;
        }

        return parent::handle($record);
    }

    protected function write(LogRecord $record): void
    {
        $this->ensureInitialized();
        $this->persistRecord($record);
    }

    public function flush(): void
    {
        $this->ensureInitialized();

        while (true) {
            $frame = $this->readNextFrame();
            if ($frame === null) {
                break;
            }

            [$record, $frameSize] = $frame;
            $this->handler->handle($record);
            $this->advanceConfirm($frameSize);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->flush();
        } finally {
            $this->closeWriteHandle();
            $this->closed = true;
        }

        $this->handler->close();
    }

    public function reset(): void
    {
        $this->flush();
        parent::reset();
        $this->resetProcessors();

        if ($this->handler instanceof ResettableInterface) {
            $this->handler->reset();
        }
    }

    public function getHandler(): HandlerInterface
    {
        return $this->handler;
    }

    public function setHandler(HandlerInterface $handler): self
    {
        $this->handler = $handler;
        return $this;
    }

    public function getSpoolDir(): string
    {
        return $this->spoolDir;
    }

    public function getWriteSegment(): int
    {
        return $this->writeSegment;
    }

    public function getWriteOffset(): int
    {
        return $this->writeOffset;
    }

    public function getConfirmSegment(): int
    {
        return $this->confirmSegment;
    }

    public function getConfirmOffset(): int
    {
        return $this->confirmOffset;
    }

    protected function ensureInitialized(): void
    {
        if ($this->initialized && $this->writeHandle !== null) {
            return;
        }

        if (!is_dir($this->spoolDir)) {
            if (!@mkdir($this->spoolDir, 0777, true)) {
                throw new \RuntimeException(sprintf('SpoolHandler: cannot create spool directory "%s"', $this->spoolDir));
            }
        }

        $this->readConfirmation();

        $segments = $this->findSegmentNumbers();

        if (empty($segments)) {
            $this->writeSegment = 1;
            $this->writeOffset = 0;
        } else {
            $this->writeSegment = max($segments);
            $this->writeOffset = $this->scanSegmentForWriteOffset($this->writeSegment);
        }

        if ($this->confirmSegment === $this->writeSegment && $this->confirmOffset > $this->writeOffset) {
            $this->confirmOffset = $this->writeOffset;
            $this->persistConfirmation();
        }

        $this->openWriteSegment($this->writeSegment);
        $this->initialized = true;
    }

    protected function readConfirmation(): void
    {
        $path = $this->spoolDir . '/' . self::CONFIRM_FILE;
        if (!file_exists($path)) {
            $this->confirmSegment = 1;
            $this->confirmOffset = 0;
            return;
        }

        $data = @file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException(sprintf('SpoolHandler: cannot read confirmation file "%s"', $path));
        }

        $decoded = json_decode($data, true);
        if (!is_array($decoded) || !isset($decoded['segment'], $decoded['offset'])) {
            throw new \RuntimeException(sprintf('SpoolHandler: invalid confirmation file "%s"', $path));
        }

        $this->confirmSegment = (int) $decoded['segment'];
        $this->confirmOffset = (int) $decoded['offset'];
    }

    protected function persistConfirmation(): void
    {
        $data = json_encode([
            'segment' => $this->confirmSegment,
            'offset' => $this->confirmOffset,
        ]);

        $tmpPath = $this->spoolDir . '/' . self::CONFIRM_TMP;
        $path = $this->spoolDir . '/' . self::CONFIRM_FILE;

        if (@file_put_contents($tmpPath, $data) === false) {
            throw new \RuntimeException(sprintf('SpoolHandler: cannot write confirmation file "%s"', $tmpPath));
        }

        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException(sprintf('SpoolHandler: cannot atomically replace confirmation file "%s"', $path));
        }
    }

    protected function findSegmentNumbers(): array
    {
        $numbers = [];
        $pattern = $this->spoolDir . '/' . self::SEGMENT_PREFIX . '*' . self::SEGMENT_SUFFIX;
        foreach (glob($pattern) as $file) {
            $name = basename($file);
            if (preg_match('/^' . self::SEGMENT_PREFIX . '(\d+)' . self::SEGMENT_SUFFIX . '$/', $name, $matches)) {
                $numbers[] = (int) $matches[1];
            }
        }
        sort($numbers);
        return $numbers;
    }

    protected function segmentPath(int $segment): string
    {
        return $this->spoolDir . '/' . self::SEGMENT_PREFIX . str_pad((string) $segment, 6, '0', STR_PAD_LEFT) . self::SEGMENT_SUFFIX;
    }

    protected function openWriteSegment(int $segment): void
    {
        $this->closeWriteHandle();
        $path = $this->segmentPath($segment);
        $handle = @fopen($path, 'a');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('SpoolHandler: cannot open segment file "%s" for writing', $path));
        }
        $this->writeHandle = $handle;
    }

    protected function closeWriteHandle(): void
    {
        if ($this->writeHandle !== null) {
            @fclose($this->writeHandle);
            $this->writeHandle = null;
        }
    }

    protected function scanSegmentForWriteOffset(int $segment): int
    {
        $path = $this->segmentPath($segment);
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('SpoolHandler: cannot open segment file "%s" for reading', $path));
        }

        $offset = 0;
        $fileSize = filesize($path);

        while ($offset < $fileSize) {
            $frame = $this->readFrameAt($handle, $offset, $segment, false);
            if ($frame === null) {
                $this->truncateSegment($path, $offset);
                break;
            }
            [$payload, $frameSize] = $frame;
            $offset += $frameSize;
        }

        fclose($handle);
        return $offset;
    }

    protected function readFrameAt($handle, int $offset, int $segment, bool $verifyChecksum = true): ?array
    {
        fseek($handle, $offset);
        $header = fread($handle, self::FRAME_OVERHEAD);
        if ($header === false || strlen($header) < self::FRAME_OVERHEAD) {
            return null;
        }

        $data = unpack('Nlength/Nchecksum', $header);
        $length = $data['length'];
        $checksum = $data['checksum'];

        if ($length <= 0) {
            return null;
        }

        $payload = fread($handle, $length);
        if ($payload === false || strlen($payload) < $length) {
            return null;
        }

        if ($verifyChecksum) {
            $actualChecksum = crc32($payload);
            if ($actualChecksum !== $checksum) {
                throw new \RuntimeException(sprintf(
                    'SpoolHandler: corrupted frame in segment %d at offset %d (expected checksum %u, got %u)',
                    $segment,
                    $offset,
                    $checksum,
                    $actualChecksum
                ));
            }
        }

        return [$payload, self::FRAME_OVERHEAD + $length];
    }

    protected function truncateSegment(string $path, int $offset): void
    {
        $handle = @fopen($path, 'r+');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('SpoolHandler: cannot open segment file "%s" for truncation', $path));
        }
        if (!@ftruncate($handle, $offset)) {
            fclose($handle);
            throw new \RuntimeException(sprintf('SpoolHandler: cannot truncate segment file "%s" at offset %d', $path, $offset));
        }
        fclose($handle);
    }

    protected function persistRecord(LogRecord $record): void
    {
        $payload = serialize($record);
        $frame = $this->packFrame($payload);

        $written = @fwrite($this->writeHandle, $frame);
        if ($written === false || $written < strlen($frame)) {
            throw new \RuntimeException(sprintf(
                'SpoolHandler: failed to write record to segment %d at offset %d',
                $this->writeSegment,
                $this->writeOffset
            ));
        }

        $this->writeOffset += strlen($frame);

        if ($this->segmentLimit > 0 && $this->writeOffset >= $this->segmentLimit) {
            $this->rotateSegment();
        }
    }

    protected function packFrame(string $payload): string
    {
        return pack('N', strlen($payload)) . pack('N', crc32($payload)) . $payload;
    }

    protected function rotateSegment(): void
    {
        $this->closeWriteHandle();
        $this->writeSegment++;

        if ($this->maxSegments > 0 && $this->writeSegment > $this->maxSegments) {
            throw new \RuntimeException(sprintf(
                'SpoolHandler: maximum number of segments (%d) exceeded',
                $this->maxSegments
            ));
        }

        $this->writeOffset = 0;
        $this->openWriteSegment($this->writeSegment);
    }

    protected function readNextFrame(): ?array
    {
        $segment = $this->confirmSegment;
        $offset = $this->confirmOffset;

        while (true) {
            $path = $this->segmentPath($segment);
            if (!file_exists($path)) {
                return null;
            }

            $handle = @fopen($path, 'r');
            if ($handle === false) {
                throw new \RuntimeException(sprintf('SpoolHandler: cannot open segment file "%s" for reading', $path));
            }

            $fileSize = filesize($path);
            if ($offset >= $fileSize) {
                fclose($handle);
                $segment++;
                $offset = 0;
                continue;
            }

            $frame = $this->readFrameAt($handle, $offset, $segment);
            fclose($handle);

            if ($frame === null) {
                return null;
            }

            [$payload, $frameSize] = $frame;
            $record = unserialize($payload);

            if (!$record instanceof LogRecord) {
                throw new \RuntimeException(sprintf(
                    'SpoolHandler: invalid record in segment %d at offset %d',
                    $segment,
                    $offset
                ));
            }

            return [$record, $frameSize];
        }
    }

    protected function advanceConfirm(int $frameSize): void
    {
        $this->confirmOffset += $frameSize;

        $path = $this->segmentPath($this->confirmSegment);
        if (file_exists($path)) {
            $fileSize = filesize($path);
            // Only advance to next segment if we are past the current segment
            // and this is not the write segment (new records may be appended)
            if ($this->confirmOffset >= $fileSize && $this->confirmSegment < $this->writeSegment) {
                $this->confirmSegment++;
                $this->confirmOffset = 0;
            }
        }

        $this->persistConfirmation();
    }
}
