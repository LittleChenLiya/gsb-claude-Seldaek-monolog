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

use Monolog\Level;

class DuplicateCountProcessorTest extends \Monolog\Test\MonologTestCase
{
    public function testConsecutiveDuplicatesGetSequentialCounts()
    {
        $processor = new DuplicateCountProcessor();

        $this->assertSame(1, $processor($this->getRecord())->extra['duplicate_count']);
        $this->assertSame(2, $processor($this->getRecord())->extra['duplicate_count']);
        $this->assertSame(3, $processor($this->getRecord())->extra['duplicate_count']);
    }

    public function testDifferentMessageStartsANewSequence()
    {
        $processor = new DuplicateCountProcessor();

        $this->assertSame(1, $processor($this->getRecord(message: 'a'))->extra['duplicate_count']);
        $this->assertSame(1, $processor($this->getRecord(message: 'b'))->extra['duplicate_count']);
        $this->assertSame(2, $processor($this->getRecord(message: 'a'))->extra['duplicate_count']);
    }

    public function testLevelIsPartOfTheIdentity()
    {
        $processor = new DuplicateCountProcessor();

        $this->assertSame(1, $processor($this->getRecord(Level::Warning))->extra['duplicate_count']);
        $this->assertSame(1, $processor($this->getRecord(Level::Info))->extra['duplicate_count']);
        $this->assertSame(1, $processor($this->getRecord(Level::Error))->extra['duplicate_count']);
        $this->assertSame(2, $processor($this->getRecord(Level::Warning))->extra['duplicate_count']);
        $this->assertSame(2, $processor($this->getRecord(Level::Info))->extra['duplicate_count']);
    }

    public function testChannelIsPartOfTheIdentity()
    {
        $processor = new DuplicateCountProcessor();

        $this->assertSame(1, $processor($this->getRecord(channel: 'app'))->extra['duplicate_count']);
        $this->assertSame(1, $processor($this->getRecord(channel: 'worker'))->extra['duplicate_count']);
        $this->assertSame(2, $processor($this->getRecord(channel: 'app'))->extra['duplicate_count']);
    }

    public function testDatetimeIsNotPartOfTheIdentity()
    {
        $processor = new DuplicateCountProcessor();

        $first = $this->getRecord(datetime: new \DateTimeImmutable('2026-10-04 10:00:00'));
        $second = $this->getRecord(datetime: new \DateTimeImmutable('2030-01-01 00:00:00'));

        $this->assertSame(1, $processor($first)->extra['duplicate_count']);
        $this->assertSame(2, $processor($second)->extra['duplicate_count']);
    }

    public function testContextScalarTypesAreDistinguished()
    {
        $processor = new DuplicateCountProcessor(['retry']);

        // int 1 and string "1" are different identities, each starts at 1
        $this->assertSame(1, $processor($this->getRecord(context: ['retry' => 1]))->extra['duplicate_count']);
        $this->assertSame(1, $processor($this->getRecord(context: ['retry' => '1']))->extra['duplicate_count']);
        // missing field and explicit null share the same "nil" identity
        $this->assertSame(1, $processor($this->getRecord(context: []))->extra['duplicate_count']);
        $this->assertSame(2, $processor($this->getRecord(context: ['retry' => null]))->extra['duplicate_count']);
        // and the typed values keep counting independently
        $this->assertSame(2, $processor($this->getRecord(context: ['retry' => 1]))->extra['duplicate_count']);
        $this->assertSame(2, $processor($this->getRecord(context: ['retry' => '1']))->extra['duplicate_count']);
        // bool and float are distinct from int/string as well
        $this->assertSame(1, $processor($this->getRecord(context: ['retry' => true]))->extra['duplicate_count']);
        $this->assertSame(1, $processor($this->getRecord(context: ['retry' => 1.0]))->extra['duplicate_count']);
    }

    public function testContextFieldOrderDoesNotChangeIdentity()
    {
        $record = fn (array $context) => $this->getRecord(context: $context);

        // the order in which fields are listed in the constructor is irrelevant
        $ab = new DuplicateCountProcessor(['a', 'b']);
        $ba = new DuplicateCountProcessor(['b', 'a']);
        $this->assertSame(1, $ab($record(['a' => 1, 'b' => 2]))->extra['duplicate_count']);
        $this->assertSame(1, $ba($record(['a' => 1, 'b' => 2]))->extra['duplicate_count']);
        $this->assertSame(2, $ab($record(['a' => 1, 'b' => 2]))->extra['duplicate_count']);
        $this->assertSame(2, $ba($record(['a' => 1, 'b' => 2]))->extra['duplicate_count']);

        // the key order inside the record context is irrelevant too
        $processor = new DuplicateCountProcessor(['a', 'b']);
        $this->assertSame(1, $processor($record(['a' => 1, 'b' => 2]))->extra['duplicate_count']);
        $this->assertSame(2, $processor($record(['b' => 2, 'a' => 1]))->extra['duplicate_count']);
    }

    public function testNonScalarContextValuesAreRejected()
    {
        $processor = new DuplicateCountProcessor(['payload']);

        $this->expectException(\InvalidArgumentException::class);
        $processor($this->getRecord(context: ['payload' => new \stdClass()]));
    }

    public function testArrayContextValuesAreRejected()
    {
        $processor = new DuplicateCountProcessor(['payload']);

        $this->expectException(\InvalidArgumentException::class);
        $processor($this->getRecord(context: ['payload' => ['nested' => 1]]));
    }

    public function testCacheEvictsLeastRecentlyUsedIdentityAtCapacity()
    {
        $processor = new DuplicateCountProcessor([], 2);
        $a = fn () => $this->getRecord(message: 'a');
        $b = fn () => $this->getRecord(message: 'b');
        $c = fn () => $this->getRecord(message: 'c');

        $this->assertSame(1, $processor($a())->extra['duplicate_count']);
        $this->assertSame(1, $processor($b())->extra['duplicate_count']);
        // hitting "a" makes it the most recently used, "b" is now the LRU entry
        $this->assertSame(2, $processor($a())->extra['duplicate_count']);
        // capacity is 2, so the third identity evicts "b"
        $this->assertSame(1, $processor($c())->extra['duplicate_count']);
        // "a" is still cached and keeps counting, "b" was evicted and restarts at 1
        $this->assertSame(3, $processor($a())->extra['duplicate_count']);
        $this->assertSame(1, $processor($b())->extra['duplicate_count']);
    }

    public function testCacheBoundaryIsRespectedExactly()
    {
        $processor = new DuplicateCountProcessor([], 2);

        $this->assertSame(1, $processor($this->getRecord(message: 'a'))->extra['duplicate_count']);
        $this->assertSame(1, $processor($this->getRecord(message: 'b'))->extra['duplicate_count']);
        // exactly at capacity: no eviction happens yet
        $this->assertSame(2, $processor($this->getRecord(message: 'a'))->extra['duplicate_count']);
        $this->assertSame(2, $processor($this->getRecord(message: 'b'))->extra['duplicate_count']);
        $this->assertCount(2, $processor->getCounts());
    }

    public function testResetClearsTheCacheAndSequencesRestartAtOne()
    {
        $processor = new DuplicateCountProcessor();

        $this->assertSame(1, $processor($this->getRecord())->extra['duplicate_count']);
        $this->assertSame(2, $processor($this->getRecord())->extra['duplicate_count']);

        $processor->reset();
        $this->assertSame([], $processor->getCounts());

        // the first record after reset is numbered 1 again, no count is inherited
        $this->assertSame(1, $processor($this->getRecord())->extra['duplicate_count']);
        $this->assertSame(2, $processor($this->getRecord())->extra['duplicate_count']);
    }

    public function testOriginalRecordIsNotModified()
    {
        $processor = new DuplicateCountProcessor();

        $record = $this->getRecord();
        $processed = $processor($record);

        $this->assertNotSame($record, $processed);
        $this->assertSame([], $record->extra);
        $this->assertSame(1, $processed->extra['duplicate_count']);
    }

    public function testExistingExtraDataIsPreservedAndCustomKeyIsUsed()
    {
        $processor = new DuplicateCountProcessor([], 10, 'dup_seq');

        $processed = $processor($this->getRecord(extra: ['uid' => 'abc']));

        $this->assertSame('abc', $processed->extra['uid']);
        $this->assertSame(1, $processed->extra['dup_seq']);
        $this->assertArrayNotHasKey('duplicate_count', $processed->extra);
    }

    public function testInvalidCapacityIsRejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        new DuplicateCountProcessor([], 0);
    }
}
