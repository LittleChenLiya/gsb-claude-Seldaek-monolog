<?php declare(strict_types=1);

namespace Monolog\Handler;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class SpoolHandlerTest extends TestCase
{
    private string $spoolDir;

    protected function setUp(): void
    {
        $this->spoolDir = sys_get_temp_dir() . '/spoolhandler-test-' . uniqid();
    }

    protected function tearDown(): void
    {
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

    private function getRecord(int|Level $level = Level::Debug, string $message = 'test', array $context = []): LogRecord
    {
        return new LogRecord(
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            'test',
            $level instanceof Level ? $level : Level::from($level),
            $message,
            $context,
            [],
            null,
        );
    }

    public function testWriteAndFlush(): void
    {
        $test = new TestHandler();
        $handler = new SpoolHandler($test, $this->spoolDir);

        $handler->handle($this->getRecord(Level::Debug, 'msg1'));
        $handler->handle($this->getRecord(Level::Info, 'msg2'));
        $handler->handle($this->getRecord(Level::Warning, 'msg3'));

        $this->assertCount(0, $test->getRecords());

        $handler->flush();

        $this->assertCount(3, $test->getRecords());
        $this->assertEquals('msg1', $test->getRecords()[0]->message);
        $this->assertEquals('msg2', $test->getRecords()[1]->message);
        $this->assertEquals('msg3', $test->getRecords()[2]->message);
    }

    public function testSecondRecordFailsReopenContinues(): void
    {
        $test = new FailOnSecondHandler();
        $handler = new SpoolHandler($test, $this->spoolDir);

        $handler->handle($this->getRecord(Level::Debug, 'msg1'));
        $handler->handle($this->getRecord(Level::Info, 'msg2'));
        $handler->handle($this->getRecord(Level::Warning, 'msg3'));

        try {
            $handler->flush();
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('fail on second', $e->getMessage());
        }

        $this->assertCount(1, $test->getRecords());
        $this->assertEquals('msg1', $test->getRecords()[0]->message);

        $test2 = new TestHandler();
        $handler2 = new SpoolHandler($test2, $this->spoolDir);
        $handler2->flush();

        $this->assertCount(2, $test2->getRecords());
        $this->assertEquals('msg2', $test2->getRecords()[0]->message);
        $this->assertEquals('msg3', $test2->getRecords()[1]->message);
    }

    public function testCrashBeforeConfirmationDuplicateOnReopen(): void
    {
        $deliveredLog = $this->spoolDir . '/delivered.log';
        @mkdir($this->spoolDir, 0777, true);

        $downstream = new DeliveredLogHandler($deliveredLog);
        $handler = new CrashBeforeConfirmSpoolHandler($downstream, $this->spoolDir);

        $handler->handle($this->getRecord(Level::Debug, 'msg1'));
        $handler->handle($this->getRecord(Level::Info, 'msg2'));
        $handler->handle($this->getRecord(Level::Warning, 'msg3'));

        try {
            $handler->flush();
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('crash before confirm', $e->getMessage());
        }

        $delivered = file($deliveredLog, FILE_IGNORE_NEW_LINES);
        $this->assertCount(1, $delivered);
        $this->assertEquals('msg1', $delivered[0]);

        $downstream2 = new DeliveredLogHandler($deliveredLog);
        $handler2 = new SpoolHandler($downstream2, $this->spoolDir);
        $handler2->flush();

        $delivered = file($deliveredLog, FILE_IGNORE_NEW_LINES);
        $this->assertCount(4, $delivered);
        $this->assertEquals('msg1', $delivered[0]);
        $this->assertEquals('msg1', $delivered[1]);
        $this->assertEquals('msg2', $delivered[2]);
        $this->assertEquals('msg3', $delivered[3]);
    }

    public function testCorruptionStopsAndReportsPosition(): void
    {
        $test = new TestHandler();
        $handler = new NoFlushSpoolHandler($test, $this->spoolDir);

        $handler->handle($this->getRecord(Level::Debug, 'msg1'));
        $handler->handle($this->getRecord(Level::Info, 'msg2'));

        $segPath = $this->spoolDir . '/spool-000001.log';
        $data = file_get_contents($segPath);
        $header = substr($data, 0, 8);
        $unpacked = unpack('Nlength/Nchecksum', $header);
        $firstFrameSize = 8 + $unpacked['length'];
        $corruptOffset = $firstFrameSize + 8;
        $data[$corruptOffset] = chr(ord($data[$corruptOffset]) ^ 0xFF);
        file_put_contents($segPath, $data);

        $test2 = new TestHandler();
        $handler2 = new SpoolHandler($test2, $this->spoolDir);

        try {
            $handler2->flush();
            $this->fail('Expected corruption exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('corrupted frame', $e->getMessage());
            $this->assertStringContainsString('segment 1', $e->getMessage());
            $this->assertStringContainsString('offset ' . $firstFrameSize, $e->getMessage());
        }

        $this->assertCount(1, $test2->getRecords());
        $this->assertEquals('msg1', $test2->getRecords()[0]->message);
    }

    public function testTailTruncationRecoverable(): void
    {
        $test = new TestHandler();
        $handler = new NoFlushSpoolHandler($test, $this->spoolDir);

        $handler->handle($this->getRecord(Level::Debug, 'msg1'));
        $handler->handle($this->getRecord(Level::Info, 'msg2'));

        $segPath = $this->spoolDir . '/spool-000001.log';
        $data = file_get_contents($segPath);
        $header = substr($data, 0, 8);
        $unpacked = unpack('Nlength/Nchecksum', $header);
        $firstFrameSize = 8 + $unpacked['length'];
        $truncated = substr($data, 0, $firstFrameSize + 4);
        file_put_contents($segPath, $truncated);

        $test2 = new TestHandler();
        $handler2 = new SpoolHandler($test2, $this->spoolDir);
        $handler2->flush();

        $this->assertCount(1, $test2->getRecords());
        $this->assertEquals('msg1', $test2->getRecords()[0]->message);

        $handler2->handle($this->getRecord(Level::Warning, 'msg3'));
        $handler2->flush();

        $this->assertCount(2, $test2->getRecords());
        $this->assertEquals('msg3', $test2->getRecords()[1]->message);
    }

    public function testSegmentLimitRotation(): void
    {
        $test = new TestHandler();
        $handler = new SpoolHandler($test, $this->spoolDir, 100, 0);

        $handler->handle($this->getRecord(Level::Debug, 'msg1'));
        $handler->handle($this->getRecord(Level::Info, 'msg2'));
        $handler->handle($this->getRecord(Level::Warning, 'msg3'));

        $segments = glob($this->spoolDir . '/spool-*.log');
        $this->assertGreaterThan(1, count($segments));

        $handler->flush();
        $this->assertCount(3, $test->getRecords());
    }

    public function testMaxSegmentsExceeded(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('maximum number of segments');

        $test = new TestHandler();
        $handler = new SpoolHandler($test, $this->spoolDir, 50, 2);

        $handler->handle($this->getRecord(Level::Debug, 'msg1'));
        $handler->handle($this->getRecord(Level::Info, 'msg2'));
        $handler->handle($this->getRecord(Level::Warning, 'msg3'));
    }

    public function testCloseMultipleTimes(): void
    {
        $test = new TestHandler();
        $handler = new SpoolHandler($test, $this->spoolDir);

        $handler->handle($this->getRecord(Level::Debug, 'msg1'));
        $handler->close();
        $handler->close();
        $handler->close();

        $this->assertCount(1, $test->getRecords());
    }

    public function testCloseFlushesRecords(): void
    {
        $test = new TestHandler();
        $handler = new SpoolHandler($test, $this->spoolDir);

        $handler->handle($this->getRecord(Level::Debug, 'msg1'));
        $handler->close();

        $this->assertCount(1, $test->getRecords());

        $test2 = new TestHandler();
        $handler2 = new SpoolHandler($test2, $this->spoolDir);
        $handler2->flush();

        $this->assertCount(0, $test2->getRecords());
    }

    public function testDiskWriteFailure(): void
    {
        $this->markTestSkipped('Disk write failure tested via stream wrapper in separate test');
    }
    public function testCorruptedConfirmationFileThrows(): void
    {
        $test = new TestHandler();
        $handler = new SpoolHandler($test, $this->spoolDir);
        $handler->handle($this->getRecord(Level::Debug, 'msg1'));
        $handler->close();

        // Corrupt the confirmation file
        file_put_contents($this->spoolDir . '/spool.confirm', 'invalid json');

        $test2 = new TestHandler();
        $handler2 = new SpoolHandler($test2, $this->spoolDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid confirmation file');

        $handler2->flush();
    }

    public function testConfirmationAtomicReplacement(): void
    {
        $test = new TestHandler();
        $handler = new SpoolHandler($test, $this->spoolDir);
        $handler->handle($this->getRecord(Level::Debug, 'msg1'));
        $handler->flush();

        // Temp file should not exist after successful confirmation
        $this->assertFileDoesNotExist($this->spoolDir . '/spool.confirm.tmp');
        $this->assertFileExists($this->spoolDir . '/spool.confirm');

        // Confirmation file should be valid JSON
        $data = json_decode(file_get_contents($this->spoolDir . '/spool.confirm'), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('segment', $data);
        $this->assertArrayHasKey('offset', $data);
    }
}

class TestHandler extends Handler
{
    private array $records = [];

    public function isHandling(LogRecord $record): bool
    {
        return true;
    }

    public function handle(LogRecord $record): bool
    {
        $this->records[] = $record;
        return false;
    }

    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->records[] = $record;
        }
    }

    public function getRecords(): array
    {
        return $this->records;
    }

    public function close(): void
    {
    }
}

class FailOnSecondHandler extends Handler
{
    private array $records = [];
    private int $count = 0;

    public function isHandling(LogRecord $record): bool
    {
        return true;
    }

    public function handle(LogRecord $record): bool
    {
        $this->count++;
        if ($this->count === 2) {
            throw new \RuntimeException('fail on second record');
        }
        $this->records[] = $record;
        return false;
    }

    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }

    public function getRecords(): array
    {
        return $this->records;
    }

    public function close(): void
    {
    }
}

class DeliveredLogHandler extends Handler
{
    private string $logFile;

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
    }

    public function isHandling(LogRecord $record): bool
    {
        return true;
    }

    public function handle(LogRecord $record): bool
    {
        file_put_contents($this->logFile, $record->message . PHP_EOL, FILE_APPEND);
        return false;
    }

    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }

    public function close(): void
    {
    }
}

class CrashBeforeConfirmSpoolHandler extends SpoolHandler
{
    private int $confirmCalls = 0;

    protected function persistConfirmation(): void
    {
        $this->confirmCalls++;
        if ($this->confirmCalls === 1) {
            throw new \RuntimeException('crash before confirm');
        }
        parent::persistConfirmation();
    }
}

class NoFlushSpoolHandler extends SpoolHandler
{
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closeWriteHandle();
        $this->closed = true;
    }
}