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
use Monolog\LogRecord;
use Monolog\Test\MonologTestCase;

/**
 * @covers Monolog\Processor\DuplicateCounterProcessor
 */
class DuplicateCounterProcessorTest extends MonologTestCase
{
    /**
     * @covers Monolog\Processor\DuplicateCounterProcessor::__invoke
     */
    public function testSameIdentityConsecutiveRecordsSequence1And2(): void
    {
        $processor = new DuplicateCounterProcessor(10, ['user_id']);

        $record1 = $processor($this->getRecord(
            Level::Warning,
            'User logged in',
            ['user_id' => 1]
        ));
        $this->assertSame(1, $record1->extra['duplicate_count']);

        $record2 = $processor($this->getRecord(
            Level::Warning,
            'User logged in',
            ['user_id' => 1]
        ));
        $this->assertSame(2, $record2->extra['duplicate_count']);
    }

    /**
     * @covers Monolog\Processor\DuplicateCounterProcessor::__invoke
     */
    public function testContextChangeFromInt1ToString1RestartsFrom1(): void
    {
        $processor = new DuplicateCounterProcessor(10, ['user_id']);

        $recordInt = $processor($this->getRecord(
            Level::Warning,
            'User logged in',
            ['user_id' => 1]
        ));
        $this->assertSame(1, $recordInt->extra['duplicate_count']);

        $recordString = $processor($this->getRecord(
            Level::Warning,
            'User logged in',
            ['user_id' => '1']
        ));
        $this->assertSame(1, $recordString->extra['duplicate_count']);
    }

    /**
     * @covers Monolog\Processor\DuplicateCounterProcessor::__invoke
     */
    public function testCapacity2ThirdIdentityEvictsOldEntry(): void
    {
        $processor = new DuplicateCounterProcessor(2, ['user_id']);

        // Identity A
        $recordA1 = $processor($this->getRecord(
            Level::Warning,
            'Message A',
            ['user_id' => 1]
        ));
        $this->assertSame(1, $recordA1->extra['duplicate_count']);

        // Identity B
        $recordB1 = $processor($this->getRecord(
            Level::Warning,
            'Message B',
            ['user_id' => 2]
        ));
        $this->assertSame(1, $recordB1->extra['duplicate_count']);

        // Identity A again - should still be tracked (moved to MRU)
        $recordA2 = $processor($this->getRecord(
            Level::Warning,
            'Message A',
            ['user_id' => 1]
        ));
        $this->assertSame(2, $recordA2->extra['duplicate_count']);

        // Identity C - should evict B (LRU)
        $recordC1 = $processor($this->getRecord(
            Level::Warning,
            'Message C',
            ['user_id' => 3]
        ));
        $this->assertSame(1, $recordC1->extra['duplicate_count']);

        // Identity B again - was evicted, so starts fresh at 1
        $recordB2 = $processor($this->getRecord(
            Level::Warning,
            'Message B',
            ['user_id' => 2]
        ));
        $this->assertSame(1, $recordB2->extra['duplicate_count']);
    }

    /**
     * @covers Monolog\Processor\DuplicateCounterProcessor::reset
     */
    public function testResetClearsCacheAndFirstIs1Again(): void
    {
        $processor = new DuplicateCounterProcessor(10, ['user_id']);

        $record1 = $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['user_id' => 1]
        ));
        $this->assertSame(1, $record1->extra['duplicate_count']);

        $record2 = $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['user_id' => 1]
        ));
        $this->assertSame(2, $record2->extra['duplicate_count']);

        $processor->reset();

        $recordAfterReset = $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['user_id' => 1]
        ));
        $this->assertSame(1, $recordAfterReset->extra['duplicate_count']);
    }

    /**
     * @covers Monolog\Processor\DuplicateCounterProcessor::__invoke
     */
    public function testFieldOrderDoesNotMatter(): void
    {
        // Fields passed in reverse order
        $processor = new DuplicateCounterProcessor(10, ['b', 'a']);

        $record1 = $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['a' => 1, 'b' => 2]
        ));
        $this->assertSame(1, $record1->extra['duplicate_count']);

        // Same identity but context fields in different order
        $record2 = $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['b' => 2, 'a' => 1]
        ));
        $this->assertSame(2, $record2->extra['duplicate_count']);
    }

    /**
     * @covers Monolog\Processor\DuplicateCounterProcessor::__invoke
     */
    public function testDifferentLevelsProduceDifferentIdentities(): void
    {
        $processor = new DuplicateCounterProcessor(10, ['user_id']);

        $recordWarning = $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['user_id' => 1]
        ));
        $this->assertSame(1, $recordWarning->extra['duplicate_count']);

        $recordError = $processor($this->getRecord(
            Level::Error,
            'Test message',
            ['user_id' => 1]
        ));
        $this->assertSame(1, $recordError->extra['duplicate_count']);
    }

    /**
     * @covers Monolog\Processor\DuplicateCounterProcessor::__invoke
     */
    public function testCacheBoundaryEviction(): void
    {
        $processor = new DuplicateCounterProcessor(3, ['user_id']);

        // Fill cache to capacity
        for ($i = 1; $i <= 3; $i++) {
            $record = $processor($this->getRecord(
                Level::Warning,
                "Message $i",
                ['user_id' => $i]
            ));
            $this->assertSame(1, $record->extra['duplicate_count']);
        }

        // Access identity 1 again (moves to MRU)
        $record = $processor($this->getRecord(
            Level::Warning,
            'Message 1',
            ['user_id' => 1]
        ));
        $this->assertSame(2, $record->extra['duplicate_count']);

        // Add identity 4 - should evict identity 2 (LRU)
        $record = $processor($this->getRecord(
            Level::Warning,
            'Message 4',
            ['user_id' => 4]
        ));
        $this->assertSame(1, $record->extra['duplicate_count']);

        // Identity 2 should have been evicted
        $record = $processor($this->getRecord(
            Level::Warning,
            'Message 2',
            ['user_id' => 2]
        ));
        $this->assertSame(1, $record->extra['duplicate_count']);
    }

    /**
     * @covers Monolog\Processor\DuplicateCounterProcessor::__invoke
     */
    public function testMissingFieldVsNullVsValue(): void
    {
        $processor = new DuplicateCounterProcessor(10, ['field']);

        // With integer value
        $recordInt = $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['field' => 1]
        ));
        $this->assertSame(1, $recordInt->extra['duplicate_count']);

        // With string value (different identity)
        $recordString = $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['field' => '1']
        ));
        $this->assertSame(1, $recordString->extra['duplicate_count']);

        // With null value (different identity)
        $recordNull = $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['field' => null]
        ));
        $this->assertSame(1, $recordNull->extra['duplicate_count']);

        // With field missing (different identity)
        $recordMissing = $processor($this->getRecord(
            Level::Warning,
            'Test message',
            []
        ));
        $this->assertSame(1, $recordMissing->extra['duplicate_count']);
    }

    /**
     * @covers Monolog\Processor\DuplicateCounterProcessor::__invoke
     */
    public function testObjectContextValueRejected(): void
    {
        $processor = new DuplicateCounterProcessor(10, ['field']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Context field "field" value must be scalar or null');

        $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['field' => new \stdClass()]
        ));
    }

    /**
     * @covers Monolog\Processor\DuplicateCounterProcessor::__invoke
     */
    public function testArrayContextValueRejected(): void
    {
        $processor = new DuplicateCounterProcessor(10, ['field']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Context field "field" value must be scalar or null');

        $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['field' => [1, 2, 3]]
        ));
    }

    /**
     * @covers Monolog\Processor\DuplicateCounterProcessor::__invoke
     */
    public function testOriginalRecordNotModified(): void
    {
        $processor = new DuplicateCounterProcessor(10, ['user_id']);

        $record = $this->getRecord(
            Level::Warning,
            'Test message',
            ['user_id' => 1]
        );

        $processed = $processor($record);

        // Original record should not have duplicate_count in extra
        $this->assertArrayNotHasKey('duplicate_count', $record->extra);
        // Processed record should have duplicate_count
        $this->assertArrayHasKey('duplicate_count', $processed->extra);
        $this->assertSame(1, $processed->extra['duplicate_count']);
    }

    /**
     * @covers Monolog\Processor\DuplicateCounterProcessor::__construct
     */
    public function testConstructorRejectsZeroCapacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Capacity must be at least 1');

        new DuplicateCounterProcessor(0);
    }

    /**
     * @covers Monolog\Processor\DuplicateCounterProcessor::__construct
     */
    public function testConstructorRejectsNegativeCapacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Capacity must be at least 1');

        new DuplicateCounterProcessor(-1);
    }

    /**
     * @covers Monolog\Processor\DuplicateCounterProcessor::__invoke
     */
    public function testBoolAndFloatValuesDistinguished(): void
    {
        $processor = new DuplicateCounterProcessor(10, ['field']);

        // Boolean true
        $recordBool = $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['field' => true]
        ));
        $this->assertSame(1, $recordBool->extra['duplicate_count']);

        // Float 1.0 (different from bool true)
        $recordFloat = $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['field' => 1.0]
        ));
        $this->assertSame(1, $recordFloat->extra['duplicate_count']);

        // Integer 1 (different from both)
        $recordInt = $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['field' => 1]
        ));
        $this->assertSame(1, $recordInt->extra['duplicate_count']);
    }

    /**
     * @covers Monolog\Processor\DuplicateCounterProcessor::__invoke
     */
    public function testEmptyContextFieldsUsesChannelLevelMessageOnly(): void
    {
        $processor = new DuplicateCounterProcessor(10);

        $record1 = $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['user_id' => 1]
        ));
        $this->assertSame(1, $record1->extra['duplicate_count']);

        // Same channel/level/message but different context - should be same identity
        $record2 = $processor($this->getRecord(
            Level::Warning,
            'Test message',
            ['user_id' => 2]
        ));
        $this->assertSame(2, $record2->extra['duplicate_count']);
    }
}
