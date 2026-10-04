<?php declare(strict_types=1);

namespace Monolog\Handler;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class SpoolHandlerDiskFailureTest extends TestCase
{
    private string $spoolDir;

    protected function setUp(): void
    {
        $this->spoolDir = sys_get_temp_dir() . '/spoolhandler-diskfail-' . uniqid();
        if (!in_array("spoolfail", stream_get_wrappers())) { stream_wrapper_register("spoolfail", FailWriteStreamWrapper::class); }
    }

    protected function tearDown(): void
    {
        // Wrapper will be cleaned up when process ends
        $this->removeDir($this->spoolDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        @rmdir($dir);
    }

    public function testSegmentWriteFailureThrows(): void
    {
        $wrapperDir = 'spoolfail://' . $this->spoolDir;
        @mkdir($this->spoolDir, 0777, true);

        $test = new TestHandler();
        $handler = new FailWriteSpoolHandler($test, $wrapperDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed to write record');

        $handler->handle(new LogRecord(
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            'test',
            Level::Debug,
            'msg1',
            [],
            [],
            null,
        ));
    }

    public function testSegmentWriteFailureRecordNotConfirmed(): void
    {
        $wrapperDir = 'spoolfail://' . $this->spoolDir;
        @mkdir($this->spoolDir, 0777, true);

        $test = new TestHandler();
        $handler = new FailWriteSpoolHandler($test, $wrapperDir);

        try {
            $handler->handle(new LogRecord(
                new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                'test',
                Level::Debug,
                'msg1',
                [],
                [],
                null,
            ));
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed to write record', $e->getMessage());
        }

        // Record should not be confirmed (no confirmation file)
        $this->assertFileDoesNotExist($this->spoolDir . '/spool.confirm');

        // Reopen with a normal handler - record should be redelivered
        $test2 = new TestHandler();
        $handler2 = new SpoolHandler($test2, $this->spoolDir);
        $handler2->flush();

        $this->assertCount(0, $test2->getRecords());
    }
}

class FailWriteSpoolHandler extends SpoolHandler
{
    protected function findSegmentNumbers(): array
    {
        $realDir = str_replace('spoolfail://', '', $this->spoolDir);
        $numbers = [];
        foreach (scandir($realDir) as $file) {
            if (preg_match('/^spool-(\d+)\.log$/', $file, $matches)) {
                $numbers[] = (int) $matches[1];
            }
        }
        sort($numbers);
        return $numbers;
    }
}

class FailWriteStreamWrapper
{
    public $context;
    private $handle;

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $realPath = str_replace('spoolfail://', '', $path);
        $this->handle = @fopen($realPath, $mode);
        return $this->handle !== false;
    }

    public function stream_write($data)
    {
        return 0;
    }

    public function stream_read($count)
    {
        return fread($this->handle, $count);
    }

    public function stream_tell()
    {
        return ftell($this->handle);
    }

    public function stream_eof()
    {
        return feof($this->handle);
    }

    public function stream_seek($offset, $whence = SEEK_SET)
    {
        return fseek($this->handle, $offset, $whence);
    }

    public function stream_stat()
    {
        return fstat($this->handle);
    }

    public function stream_close()
    {
        fclose($this->handle);
    }

    public function url_stat($path, $flags)
    {
        $realPath = str_replace('spoolfail://', '', $path);
        if ($flags & STREAM_URL_STAT_QUIET) {
            return @stat($realPath);
        }
        return stat($realPath);
    }

    public function mkdir($path, $mode, $options)
    {
        $realPath = str_replace('spoolfail://', '', $path);
        return mkdir($realPath, $mode, (bool)($options & STREAM_MKDIR_RECURSIVE));
    }

    public function dir_opendir($path, $options)
    {
        return true;
    }

    public function dir_readdir()
    {
        return false;
    }

    public function dir_closedir()
    {
        return true;
    }

    public function dir_rewinddir()
    {
        return true;
    }
}
