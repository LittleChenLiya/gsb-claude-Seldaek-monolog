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

use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Test\MonologTestCase;

class SpoolHandlerTest extends MonologTestCase
{
    private string $spoolDir;

    public function setUp(): void
    {
        parent::setUp();

        $this->spoolDir = sys_get_temp_dir()
            .\DIRECTORY_SEPARATOR
            .'monolog-spool-'.getmypid().'-'.bin2hex(random_bytes(6));
        mkdir($this->spoolDir, 0777, true);

        FailingWriteStreamWrapper::unregister();
    }

    public function tearDown(): void
    {
        parent::tearDown();

        FailingWriteStreamWrapper::unregister();
        $this->removeDirectory($this->spoolDir);
    }

    /**
     * Acceptance scenario: three records are written, delivery of the second
     * one fails, and a fresh handler (process restart) continues from the
     * second record instead of replaying the first or skipping anything.
     *
     * @covers Monolog\Handler\SpoolHandler::flush
     * @covers Monolog\Handler\SpoolHandler::close
     */
    public function testDownstreamFailureResumesFromFailingRecordAfterRestart()
    {
        $downstream = new SpoolRecordingHandler();
        $downstream->failOnAttempts([2]);

        $spool = new SpoolHandler($this->spoolDir, $downstream, 0, 16 * 1024 * 1024, false);

        $spool->handle($this->getRecord(Level::Info, 'first'));
        $spool->handle($this->getRecord(Level::Info, 'second'));
        $spool->handle($this->getRecord(Level::Info, 'third'));

        $thrown = null;
        try {
            $spool->flush();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame('downstream failure on second record', $thrown->getMessage());
        $this->assertSame(['first'], $downstream->messages());
        $this->assertSame(0, $spool->getAcknowledgedSegment());
        $this->assertSame($this->frameEnds()[0], $spool->getAcknowledgedOffset());

        // Simulate a process restart: drop every in-memory object.
        $spool = null;
        unset($spool);

        $restarted = new SpoolHandler($this->spoolDir, $downstream, 0, 16 * 1024 * 1024, false);
        $delivered = $restarted->flush();
        $restarted->close();

        $this->assertSame(2, $delivered);
        $this->assertSame(['first', 'second', 'third'], $downstream->messages());
    }

    /**
     * Acceptance scenario: downstream succeeded but the acknowledgement was not
     * persisted before the crash. The record must be delivered again (an
     * allowed duplicate) rather than lost.
     *
     * @covers Monolog\Handler\SpoolHandler::flush
     */
    public function testCrashAfterDeliveryBeforeAckRedeliversInsteadOfLosing()
    {
        $downstream = new SpoolRecordingHandler();

        $crashing = new CrashBeforeAckSpoolHandler($this->spoolDir, $downstream);
        $crashing->handle($this->getRecord(Level::Info, 'crash-on-me'));

        $thrown = null;
        try {
            $crashing->flush();
        } catch (SpoolException $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(SpoolException::class, $thrown);
        $this->assertStringContainsString('simulated crash before ack', $thrown->getMessage());
        $this->assertSame(['crash-on-me'], $downstream->messages());

        // Downstream was called once, but no ack file exists.
        $this->assertFileDoesNotExist($this->spoolDir.\DIRECTORY_SEPARATOR.SpoolHandler::ACK_FILENAME);

        $restarted = new SpoolHandler($this->spoolDir, $downstream);
        $restarted->flush();
        $restarted->close();

        // Exactly one duplicate of the already delivered record, no loss.
        $this->assertSame(['crash-on-me', 'crash-on-me'], $downstream->messages());
    }

    /**
     * A checksum mismatch in the middle of a segment must stop delivery and
     * report the actual segment index and byte offset of the damaged frame.
     *
     * @covers Monolog\Handler\SpoolHandler::flush
     * @covers Monolog\Handler\SpoolCorruptionException
     */
    public function testMidSegmentCorruptionStopsAndReportsActualOffset()
    {
        $downstream = new SpoolRecordingHandler();
        $spool = new SpoolHandler($this->spoolDir, $downstream, 0, 16 * 1024 * 1024, false);
        $spool->handle($this->getRecord(Level::Info, 'alpha'));
        $spool->handle($this->getRecord(Level::Info, 'beta'));
        $spool->handle($this->getRecord(Level::Info, 'gamma'));

        $ends = $this->frameEnds();
        $segment = $this->segmentFile(0);
        $raw = (string) file_get_contents($segment);

        // Flip a byte inside the payload of the second frame.
        $damagedOffset = $ends[0] + 15 + 2;
        $raw[$damagedOffset] = chr(ord($raw[$damagedOffset]) ^ 0xFF);
        file_put_contents($segment, $raw);

        $spool = null;
        unset($spool);

        $restarted = new SpoolHandler($this->spoolDir, $downstream, 0, 16 * 1024 * 1024, false);

        try {
            $restarted->flush();
            $this->fail('Expected SpoolCorruptionException was not thrown');
        } catch (SpoolCorruptionException $e) {
            $this->assertSame(0, $e->getSegmentIndex());
            $this->assertSame($ends[0], $e->getOffset());
            $this->assertStringContainsString('checksum mismatch', $e->getMessage());
            $this->assertStringContainsString('segment 0, offset '.$ends[0], $e->getMessage());
        }

        // Delivery stops before the damaged frame: nothing was sent downstream.
        $this->assertSame([], $downstream->messages());
    }

    /**
     * Corruption inside a sealed (non-last) segment reports that segment index
     * and the real offset inside that segment file, never a wrong global value.
     *
     * @covers Monolog\Handler\SpoolHandler::flush
     */
    public function testCorruptionInSealedSegmentReportsThatSegmentAndOffset()
    {
        $downstream = new SpoolRecordingHandler();
        // Tiny segments force rollover so that three records land in three
        // segments of their own.
        $spool = new SpoolHandler($this->spoolDir, $downstream, 20, 16 * 1024 * 1024, false);
        $spool->handle($this->getRecord(Level::Info, 'a-record'));
        $spool->handle($this->getRecord(Level::Info, 'b-record'));
        $spool->handle($this->getRecord(Level::Info, 'c-record'));
        $this->assertSame(2, $spool->getActiveSegment());

        // Corrupt the payload of the only frame in segment 0: offset is 0.
        $segment = $this->segmentFile(0);
        $raw = (string) file_get_contents($segment);
        $raw[15 + 1] = chr(ord($raw[15 + 1]) ^ 0xFF);
        file_put_contents($segment, $raw);

        $spool = null;
        unset($spool);

        $restarted = new SpoolHandler($this->spoolDir, $downstream, 20, 16 * 1024 * 1024, false);

        try {
            $restarted->flush();
            $this->fail('Expected SpoolCorruptionException was not thrown');
        } catch (SpoolCorruptionException $e) {
            $this->assertSame(0, $e->getSegmentIndex());
            $this->assertSame(0, $e->getOffset());
        }

        $this->assertSame([], $downstream->messages());
    }

    /**
     * A torn tail (crash in the middle of writing the last frame) is recovered
     * by truncation, both for a torn header and a torn payload.
     *
     * @covers Monolog\Handler\SpoolHandler::__construct
     * @covers Monolog\Handler\SpoolHandler::flush
     */
    public function testTornTrailingFrameIsTruncatedAndRecovered()
    {
        // 3 bytes  : torn header (shorter than the 15 byte header)
        // 20 bytes : torn payload (valid header claiming more bytes than present)
        foreach ([3, 20] as $i => $tailBytes) {
            $this->removeDirectory($this->spoolDir);
            mkdir($this->spoolDir, 0777, true);

            $downstream = new SpoolRecordingHandler();
            $spool = new SpoolHandler($this->spoolDir, $downstream, 0, 16 * 1024 * 1024, false);
            $spool->handle($this->getRecord(Level::Info, 'complete-one'));
            $spool->handle($this->getRecord(Level::Info, 'complete-two'));

            $segment = $this->segmentFile(0);
            $sizeBefore = (int) filesize($segment);
            if ($tailBytes === 3) {
                file_put_contents($segment, str_repeat('X', $tailBytes), \FILE_APPEND);
            } else {
                // magic + version + length (64 bytes) + checksum: a complete
                // header followed by only 5 bytes of the claimed payload.
                file_put_contents(
                    $segment,
                    pack('a2CJN', 'MS', 1, 64, 12345).str_repeat('Y', 5),
                    \FILE_APPEND,
                );
            }

            $spool = null;
            unset($spool);

            $restarted = new SpoolHandler($this->spoolDir, $downstream, 0, 16 * 1024 * 1024, false);
            $delivered = $restarted->flush();
            $restarted->close();

            $this->assertSame(2, $delivered, 'torn tail scenario '.$i.' must be dropped');
            $this->assertSame(['complete-one', 'complete-two'], $downstream->messages());
            $this->assertSame($sizeBefore, (int) filesize($segment));
        }
    }

    /**
     * A short frame inside a sealed segment is corruption, not a torn tail.
     *
     * @covers Monolog\Handler\SpoolHandler::flush
     */
    public function testTruncatedFrameInSealedSegmentIsReportedAsCorruption()
    {
        $downstream = new SpoolRecordingHandler();
        $spool = new SpoolHandler($this->spoolDir, $downstream, 20, 16 * 1024 * 1024, false);
        $spool->handle($this->getRecord(Level::Info, 'a-record'));
        $spool->handle($this->getRecord(Level::Info, 'b-record'));

        // Append garbage to the now sealed first segment.
        $segment = $this->segmentFile(0);
        file_put_contents($segment, 'short', \FILE_APPEND);

        $spool = null;
        unset($spool);

        $restarted = new SpoolHandler($this->spoolDir, $downstream, 20, 16 * 1024 * 1024, false);

        try {
            $restarted->flush();
            $this->fail('Expected SpoolCorruptionException was not thrown');
        } catch (SpoolCorruptionException $e) {
            $this->assertSame(0, $e->getSegmentIndex());
            $frameEnd = $this->firstFrameSizeIn($segment);
            $this->assertSame($frameEnd, $e->getOffset());
        }
    }

    /**
     * Disk write failures surface as SpoolException and leave a recoverable
     * spool: the partial tail is truncated on reopen and retried writes work.
     *
     * @covers Monolog\Handler\SpoolHandler::write
     */
    public function testDiskWriteFailureIsReportedAndRecoverable()
    {
        FailingWriteStreamWrapper::register($this->spoolDir);
        FailingWriteStreamWrapper::$succeedWrites = 1;
        $this->assertContains(FailingWriteStreamWrapper::SCHEME, stream_get_wrappers());

        $downstream = new SpoolRecordingHandler();
        $spool = new FailingDiskSpoolHandler($this->spoolDir, $downstream);
        $spool->handle($this->getRecord(Level::Info, 'persisted'));

        try {
            $spool->handle($this->getRecord(Level::Info, 'never-saved'));
            $this->fail('Expected SpoolException was not thrown');
        } catch (SpoolException $e) {
            $this->assertStringContainsString('Failed writing record to spool segment', $e->getMessage());
        }

        // Drop the failing handler completely before unregistering the
        // wrapper, otherwise its destructor would reopen through it.
        $spool = null;
        unset($spool);
        FailingWriteStreamWrapper::unregister();

        // Reopen: the failed record produced at most a torn tail which is
        // truncated away, then new writes and flushes work normally.
        $restarted = new SpoolHandler($this->spoolDir, $downstream, 0, 16 * 1024 * 1024, false);
        $restarted->handle($this->getRecord(Level::Info, 'recovered'));
        $restarted->flush();
        $restarted->close();

        $this->assertSame(['persisted', 'recovered'], $downstream->messages());
    }

    /**
     * Encoded records larger than the configured limit are rejected before
     * touching the segment.
     *
     * @covers Monolog\Handler\SpoolHandler::write
     */
    public function testRecordExceedingSizeLimitIsRejected()
    {
        $downstream = new SpoolRecordingHandler();
        $spool = new SpoolHandler($this->spoolDir, $downstream, 0, 400, false);

        $record = $this->getRecord(Level::Info, str_repeat('z', 2000));

        try {
            $spool->handle($record);
            $this->fail('Expected SpoolException was not thrown');
        } catch (SpoolException $e) {
            $this->assertStringContainsString('exceeds the 400 bytes spool limit', $e->getMessage());
        }
        $segment = $this->segmentFile(0);
        $this->assertSame(0, (int) filesize($segment));

        $spool->handle($this->getRecord(Level::Info, 'still-works'));
        $spool->flush();
        $spool->close();

        $this->assertSame(['still-works'], $downstream->messages());
    }

    /**
     * Records roll over into new segments once the active one exceeds the
     * configured size; flush still delivers every record in order.
     *
     * @covers Monolog\Handler\SpoolHandler::write
     * @covers Monolog\Handler\SpoolHandler::flush
     */
    public function testSegmentRolloverWhenLimitExceeded()
    {
        $downstream = new SpoolRecordingHandler();
        $spool = new SpoolHandler($this->spoolDir, $downstream, 20, 16 * 1024 * 1024, false);

        $spool->handle($this->getRecord(Level::Info, 'one'));
        $spool->handle($this->getRecord(Level::Info, 'two'));
        $spool->handle($this->getRecord(Level::Info, 'three'));

        $this->assertSame(2, $spool->getActiveSegment());
        $this->assertFileExists($this->segmentFile(0));
        $this->assertFileExists($this->segmentFile(1));
        $this->assertFileExists($this->segmentFile(2));

        $this->assertSame(3, $spool->flush());
        $spool->close();

        $this->assertSame(['one', 'two', 'three'], $downstream->messages());
    }

    /**
     * close() is idempotent and can be called several times without flushing
     * the same records twice; the handler still accepts new records afterwards.
     *
     * @covers Monolog\Handler\SpoolHandler::close
     */
    public function testCloseCanBeCalledMultipleTimesAndReopens()
    {
        $downstream = new SpoolRecordingHandler();
        $spool = new SpoolHandler($this->spoolDir, $downstream);

        $spool->handle($this->getRecord(Level::Info, 'first-batch'));
        $spool->close();
        $spool->close();
        $spool->close();

        $this->assertSame(['first-batch'], $downstream->messages());

        $spool->handle($this->getRecord(Level::Info, 'second-batch'));
        $spool->close();

        $this->assertSame(['first-batch', 'second-batch'], $downstream->messages());
        $spool->close();
        $this->assertSame(['first-batch', 'second-batch'], $downstream->messages());
    }

    /**
     * The ack file is replaced atomically (no .tmp leftovers) and advanced
     * delivery is skipped after a restart.
     *
     * @covers Monolog\Handler\SpoolHandler::flush
     */
    public function testAckFileIsAtomicallyReplacedAndSkipsConfirmedRecords()
    {
        $downstream = new SpoolRecordingHandler();
        $spool = new SpoolHandler($this->spoolDir, $downstream, 0, 16 * 1024 * 1024, false);
        $spool->handle($this->getRecord(Level::Info, 'confirmed'));
        $spool->flush();

        $ackFile = $this->spoolDir.\DIRECTORY_SEPARATOR.SpoolHandler::ACK_FILENAME;
        $this->assertFileExists($ackFile);
        $this->assertSame(
            ['segment' => 0, 'offset' => $this->singleFrameSize()],
            json_decode((string) file_get_contents($ackFile), true),
        );

        foreach (glob($this->spoolDir.\DIRECTORY_SEPARATOR.'*.tmp-*') ?: [] as $leftover) {
            $this->fail('Temporary ack file was not cleaned up: '.$leftover);
        }

        $spool->handle($this->getRecord(Level::Info, 'pending'));
        $spool = null;
        unset($spool);

        $restarted = new SpoolHandler($this->spoolDir, $downstream, 0, 16 * 1024 * 1024, false);
        $this->assertSame(1, $restarted->flush());
        $restarted->close();

        $this->assertSame(['confirmed', 'pending'], $downstream->messages());
    }

    /**
     * Constructor rejects invalid limits.
     */
    public function testInvalidLimitsAreRejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        new SpoolHandler($this->spoolDir, new SpoolRecordingHandler(), -1);
    }

    /**
     * A missing segment file in the middle of the sequence must be reported
     * rather than silently skipping records.
     *
     * @covers Monolog\Handler\SpoolHandler::flush
     */
    public function testMissingMiddleSegmentIsReported()
    {
        $downstream = new SpoolRecordingHandler();
        $spool = new SpoolHandler($this->spoolDir, $downstream, 20, 16 * 1024 * 1024, false);
        $spool->handle($this->getRecord(Level::Info, 'a-record'));
        $spool->handle($this->getRecord(Level::Info, 'b-record'));
        $spool->handle($this->getRecord(Level::Info, 'c-record'));
        $this->assertFileExists($this->segmentFile(1));

        unlink($this->segmentFile(1));

        $spool = null;
        unset($spool);

        $this->expectException(SpoolException::class);
        $this->expectExceptionMessage('Missing spool segment file');

        $restarted = new SpoolHandler($this->spoolDir, $downstream, 20, 16 * 1024 * 1024, false);
        $restarted->flush();
    }

    /**
     * Delivering across multiple segments acknowledges the next segment's
     * start before removing consumed files, so a handler opened fresh after a
     * full flush neither re-delivers nor errors on vanished segments.
     *
     * @covers Monolog\Handler\SpoolHandler::flush
     */
    public function testCrossSegmentFlushAndRestartDoesNotDuplicateOrError()
    {
        $downstream = new SpoolRecordingHandler();
        $spool = new SpoolHandler($this->spoolDir, $downstream, 20, 16 * 1024 * 1024, false);
        $spool->handle($this->getRecord(Level::Info, 'seg-a'));
        $spool->handle($this->getRecord(Level::Info, 'seg-b'));
        $spool->handle($this->getRecord(Level::Info, 'seg-c'));

        $this->assertSame(3, $spool->flush());
        $this->assertSame(['seg-a', 'seg-b', 'seg-c'], $downstream->messages());

        // Consumed segments were removed after the ack advanced.
        $this->assertFileDoesNotExist($this->segmentFile(0));
        $this->assertFileDoesNotExist($this->segmentFile(1));
        $this->assertFileExists($this->segmentFile(2));

        $ack = json_decode(
            (string) file_get_contents($this->spoolDir.\DIRECTORY_SEPARATOR.SpoolHandler::ACK_FILENAME),
            true,
        );
        $this->assertSame(2, $ack['segment']);

        // Restart: nothing is left to deliver, no exception, no duplication.
        $spool = null;
        unset($spool);

        $restarted = new SpoolHandler($this->spoolDir, $downstream, 20, 16 * 1024 * 1024, false);
        $this->assertSame(0, $restarted->flush());
        $restarted->close();

        $this->assertSame(['seg-a', 'seg-b', 'seg-c'], $downstream->messages());
    }

    /**
     * An ack file pointing inside a frame is rejected instead of resuming
     * delivery from an arbitrary byte position.
     *
     * @covers Monolog\Handler\SpoolHandler::flush
     */
    public function testAckPointingInsideFrameIsRejected()
    {
        $downstream = new SpoolRecordingHandler();
        $spool = new SpoolHandler($this->spoolDir, $downstream, 0, 16 * 1024 * 1024, false);
        $spool->handle($this->getRecord(Level::Info, 'alpha'));
        $spool->handle($this->getRecord(Level::Info, 'beta'));

        $ends = $this->frameEnds();
        file_put_contents(
            $this->spoolDir.\DIRECTORY_SEPARATOR.SpoolHandler::ACK_FILENAME,
            json_encode(['segment' => 0, 'offset' => $ends[0] + 4]),
        );

        $spool = null;
        unset($spool);

        $this->expectException(SpoolException::class);
        $this->expectExceptionMessage('does not point at a frame boundary');

        $restarted = new SpoolHandler($this->spoolDir, $downstream, 0, 16 * 1024 * 1024, false);
        $restarted->flush();
    }

    /**
     * A payload with a correct frame header and checksum but undecodable
     * content stops recovery and reports the real frame offset.
     *
     * @covers Monolog\Handler\SpoolHandler::flush
     */
    public function testUndecodablePayloadStopsAndReportsOffset()
    {
        $downstream = new SpoolRecordingHandler();
        $spool = new SpoolHandler($this->spoolDir, $downstream, 0, 16 * 1024 * 1024, false);
        $spool->handle($this->getRecord(Level::Info, 'alpha'));
        $spool->handle($this->getRecord(Level::Info, 'beta'));

        $ends = $this->frameEnds();
        $segment = $this->segmentFile(0);

        // Replace the second frame payload with garbage and fix its checksum.
        $raw = (string) file_get_contents($segment);
        $payload = '{not valid json';
        $raw = substr($raw, 0, $ends[0]).pack('a2CJN', 'MS', 1, strlen($payload), crc32($payload)).$payload;
        file_put_contents($segment, $raw);

        $spool = null;
        unset($spool);

        try {
            $restarted = new SpoolHandler($this->spoolDir, $downstream, 0, 16 * 1024 * 1024, false);
            $restarted->flush();
            $this->fail('Expected SpoolCorruptionException was not thrown');
        } catch (SpoolCorruptionException $e) {
            $this->assertSame(0, $e->getSegmentIndex());
            $this->assertSame($ends[0], $e->getOffset());
            $this->assertStringContainsString('not valid JSON', $e->getMessage());
        }

        $this->assertSame([], $downstream->messages());
    }

    /**
     * A torn tail left on disk is repaired simply by constructing a new
     * handler, even without an explicit flush().
     *
     * @covers Monolog\Handler\SpoolHandler::write
     */
    public function testReopenTruncatesTornTailWithoutFlush()
    {
        $downstream = new SpoolRecordingHandler();
        $spool = new SpoolHandler($this->spoolDir, $downstream, 0, 16 * 1024 * 1024, false);
        $spool->handle($this->getRecord(Level::Info, 'one'));

        $segment = $this->segmentFile(0);
        $sizeBefore = (int) filesize($segment);
        file_put_contents($segment, str_repeat('Q', 7), \FILE_APPEND);

        $spool = null;
        unset($spool);

        $restarted = new SpoolHandler($this->spoolDir, $downstream, 0, 16 * 1024 * 1024, false);
        $restarted->handle($this->getRecord(Level::Info, 'two'));
        $restarted->flush();
        $restarted->close();

        $this->assertSame(['one', 'two'], $downstream->messages());
        // Torn 7 bytes gone, exactly two same-sized frames remain.
        $this->assertSame($sizeBefore * 2, (int) filesize($segment));
    }

    private function segmentFile(int $index): string
    {
        return $this->spoolDir
            .\DIRECTORY_SEPARATOR
            .SpoolHandler::SEGMENT_PREFIX
            .str_pad((string) $index, SpoolHandler::SEGMENT_PADDING, '0', STR_PAD_LEFT)
            .SpoolHandler::SEGMENT_SUFFIX;
    }

    /**
     * @return int[] end offsets of every frame currently present in segment 0
     */
    private function frameEnds(): array
    {
        $raw = (string) file_get_contents($this->segmentFile(0));
        $pos = 0;
        $ends = [];
        while ($pos < strlen($raw)) {
            $header = unpack('a2magic/Cversion/Jlength/Nchecksum', substr($raw, $pos, 15));
            if ($header === false || $header['magic'] !== 'MS') {
                break;
            }
            $pos += 15 + $header['length'];
            $ends[] = $pos;
        }

        return $ends;
    }

    private function singleFrameSize(): int
    {
        return (int) filesize($this->segmentFile(0));
    }

    private function firstFrameSizeIn(string $segment): int
    {
        $raw = (string) file_get_contents($segment);
        $length = unpack('Jlength', substr($raw, 3, 8))['length'];

        return 15 + $length;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach ((array) scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.\DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}

/**
 * Downstream test handler recording delivered message strings and optionally
 * failing on given delivery attempts (1-based across handler instances).
 */
class SpoolRecordingHandler extends AbstractProcessingHandler
{
    /** @var string[] */
    private array $messages = [];

    /** @var array<int, true> */
    private array $failOnAttempts = [];

    private int $attempts = 0;

    /**
     * @param int[] $attempts
     */
    public function failOnAttempts(array $attempts): void
    {
        foreach ($attempts as $attempt) {
            $this->failOnAttempts[$attempt] = true;
        }
    }

    /**
     * @return string[]
     */
    public function messages(): array
    {
        return $this->messages;
    }

    protected function write(LogRecord $record): void
    {
        ++$this->attempts;
        if (isset($this->failOnAttempts[$this->attempts])) {
            throw new \RuntimeException('downstream failure on second record');
        }

        $this->messages[] = $record->message;
    }
}

/**
 * Simulates a process crash right after downstream success and before the
 * acknowledgement is persisted: the in-memory position is rolled back and no
 * ack file is written.
 */
class CrashBeforeAckSpoolHandler extends SpoolHandler
{
    protected function persistAck(int $segment, int $offset): void
    {
        throw new SpoolException('simulated crash before ack at segment '.$segment.' offset '.$offset);
    }
}

/**
 * Opens the active segment through the failing stream wrapper.
 */
class FailingDiskSpoolHandler extends SpoolHandler
{
    protected function openActiveSegment(string $path)
    {
        return fopen(FailingWriteStreamWrapper::SCHEME.'://'.basename($path), 'cb+');
    }
}

/**
 * Stream wrapper backed by the real spool directory whose writes always fail,
 * simulating a full or failing disk.
 */
#[\AllowDynamicProperties]
class FailingWriteStreamWrapper
{
    public const SCHEME = 'monolog-failing-write';

    private static string $root = '';

    /** Number of writes forwarded to disk before writes start failing. */
    public static int $succeedWrites = 0;

    /** @var resource|null */
    private $handle;

    /** @var resource|null */
    private $dirHandle;

    public static function register(string $root): void
    {
        self::$root = $root;
        self::$succeedWrites = 0;
        self::unregister();
        stream_wrapper_register(self::SCHEME, self::class);
    }

    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    private function realPath(string $path): string
    {
        $relative = substr($path, strlen(self::SCHEME) + 3);

        return self::$root.\DIRECTORY_SEPARATOR.ltrim(str_replace('/', \DIRECTORY_SEPARATOR, $relative), '\\/');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $handle = @fopen($this->realPath($path), $mode);
        if ($handle === false) {
            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function stream_write(string $data): int
    {
        if (FailingWriteStreamWrapper::$succeedWrites > 0) {
            --FailingWriteStreamWrapper::$succeedWrites;

            return (int) fwrite($this->handle, $data);
        }

        return 0;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->handle, $count);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        return (int) ftell($this->handle);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }

    /**
     * @return array<mixed>|false
     */
    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    /**
     * @return array<mixed>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $real = $this->realPath($path);
        if ($flags & \STREAM_URL_STAT_QUIET) {
            return @stat($real);
        }

        return stat($real);
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return true;
    }
}

