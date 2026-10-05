<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Processor\Contract;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class ContractChainTest extends TestCase
{
    /**
     * The two-processor example: each processor declares the paths it may modify,
     * both run in order on the candidate record, and the writer receives the result.
     */
    public function testTwoProcessorsWithExplicitContractsRunInOrder(): void
    {
        $writer = new TestHandler();

        $processorA = new class implements ContractualProcessorInterface {
            public int $calls = 0;

            public function allowedRecordPaths(): array
            {
                return ['extra'];
            }

            public function __invoke(LogRecord $record): LogRecord
            {
                $this->calls++;

                return $record->with(extra: array_merge($record->extra, ['a' => 1]));
            }
        };

        $processorB = new class implements ContractualProcessorInterface {
            public int $calls = 0;

            public function allowedRecordPaths(): array
            {
                return ['context'];
            }

            public function __invoke(LogRecord $record): LogRecord
            {
                $this->calls++;

                return $record->with(context: array_merge($record->context, ['b' => 2]));
            }
        };

        $chain = new ContractChain([$processorA, $processorB]);

        $logger = new Logger('test', [$writer]);
        $logger->pushProcessor($chain);

        $logger->info('hello', ['orig' => 'v']);

        $this->assertSame(1, $processorA->calls, 'processor A must be invoked exactly once');
        $this->assertSame(1, $processorB->calls, 'processor B must be invoked exactly once');
        $this->assertSame(2, $chain->getCallbackCount(), 'callback count must follow the original chain');

        $records = $writer->getRecords();
        $this->assertCount(1, $records, 'writer must receive exactly one record');
        $this->assertSame(['orig' => 'v', 'b' => 2], $records[0]->context);
        $this->assertSame(['a' => 1], $records[0]->extra);
    }

    /**
     * A processor only allowed to modify "extra" that touches "context" must be
     * rejected: the writer is never called and the original record is untouched.
     */
    public function testOutOfBoundsChangeIsRejectedAndWriterIsNotCalled(): void
    {
        $writer = new TestHandler();

        $processor = new class implements ContractualProcessorInterface {
            public function allowedRecordPaths(): array
            {
                return ['extra'];
            }

            public function __invoke(LogRecord $record): LogRecord
            {
                return $record->with(context: array_merge($record->context, ['x' => 1]));
            }
        };

        $chain = new ContractChain([$processor]);

        $logger = new Logger('test', [$writer]);
        $logger->pushProcessor($chain);

        $originalContext = ['keep' => 'me'];

        try {
            $logger->info('hello', $originalContext);
            $this->fail('Expected ProcessorContractException to be thrown');
        } catch (ProcessorContractException $e) {
            $this->assertStringContainsString('context.x', $e->getMessage());
        }

        $this->assertCount(0, $writer->getRecords(), 'writer must not be called on violation');
        $this->assertSame(['keep' => 'me'], $originalContext, 'original record context must be unchanged');
    }

    /**
     * A processor allowed to modify "extra" may modify a deep path under "extra".
     */
    public function testDeepPathUnderAllowedPrefixIsAllowed(): void
    {
        $writer = new TestHandler();

        $processor = new class implements ContractualProcessorInterface {
            public function allowedRecordPaths(): array
            {
                return ['extra'];
            }

            public function __invoke(LogRecord $record): LogRecord
            {
                $extra = $record->extra;
                $extra['nested']['deep'] = 'value';

                return $record->with(extra: $extra);
            }
        };

        $logger = new Logger('test', [$writer]);
        $logger->pushProcessor(new ContractChain([$processor]));

        $logger->info('hello');

        $records = $writer->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(['nested' => ['deep' => 'value']], $records[0]->extra);
    }

    /**
     * A custom mutable object with no verification method makes the record unverifiable:
     * the chain aborts before invoking any processor (callbacks = 0) and the writer is
     * never called. No getter is invoked on the object.
     */
    public function testUnverifiableObjectAbortsBeforeAnyCallback(): void
    {
        $writer = new TestHandler();

        $counter = new \stdClass();
        $counter->calls = 0;

        $processor = new class($counter) implements ContractualProcessorInterface {
            public function __construct(private \stdClass $counter)
            {
            }

            public function allowedRecordPaths(): array
            {
                return ['extra'];
            }

            public function __invoke(LogRecord $record): LogRecord
            {
                $this->counter->calls++;

                return $record->with(extra: ['x' => 1]);
            }
        };

        $mutable = new class {
            public string $state = 'a';
        };

        $logger = new Logger('test', [$writer]);
        $logger->pushProcessor(new ContractChain([$processor]));

        try {
            $logger->info('hello', ['obj' => $mutable]);
            $this->fail('Expected ProcessorContractException to be thrown');
        } catch (ProcessorContractException $e) {
            $this->assertStringContainsString('cannot be verified', $e->getMessage());
        }

        $this->assertSame(0, $counter->calls, 'processor must not be invoked when record is unverifiable');
        $this->assertCount(0, $writer->getRecords(), 'writer must not be called when record is unverifiable');
        $this->assertSame('a', $mutable->state, 'original object must be untouched');
    }

    /**
     * Array references must be isolated: the candidate runs on a deep clone, so a
     * processor mutating nested arrays never affects the original record.
     */
    public function testCandidateRunsOnIsolatedArrayCopy(): void
    {
        $writer = new TestHandler();

        $processor = new class implements ContractualProcessorInterface {
            public function allowedRecordPaths(): array
            {
                return ['context'];
            }

            public function __invoke(LogRecord $record): LogRecord
            {
                $context = $record->context;
                $context['nested']['a'] = 2;

                return $record->with(context: $context);
            }
        };

        $original = ['nested' => ['a' => 1]];

        $logger = new Logger('test', [$writer]);
        $logger->pushProcessor(new ContractChain([$processor]));

        $logger->info('hello', $original);

        $this->assertSame(['nested' => ['a' => 1]], $original, 'original context array must be unchanged');

        $records = $writer->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(['nested' => ['a' => 2]], $records[0]->context);
    }

    /**
     * An object that provides a snapshot can be verified: a processor that modifies
     * the object's state is detected when the object's path is not allowed.
     */
    public function testObjectWithSnapshotProviderIsVerified(): void
    {
        $writer = new TestHandler();

        $processor = new class implements ContractualProcessorInterface {
            public function allowedRecordPaths(): array
            {
                return ['extra'];
            }

            public function __invoke(LogRecord $record): LogRecord
            {
                $obj = $record->context['obj'];
                $obj->state = 'b';

                return $record;
            }
        };

        $object = new class implements RecordObjectSnapshotInterface {
            public string $state = 'a';

            public function snapshotForRecordContract(): mixed
            {
                return ['state' => $this->state];
            }
        };

        $logger = new Logger('test', [$writer]);
        $logger->pushProcessor(new ContractChain([$processor]));

        try {
            $logger->info('hello', ['obj' => $object]);
            $this->fail('Expected ProcessorContractException to be thrown');
        } catch (ProcessorContractException $e) {
            $this->assertStringContainsString('context.obj', $e->getMessage());
        }

        $this->assertCount(0, $writer->getRecords(), 'writer must not be called on out-of-bounds object change');
        $this->assertSame('a', $object->state, 'original object must be untouched');
    }

    /**
     * On success the original record passed to the chain is left untouched; the writer
     * receives the processed candidate.
     */
    public function testOriginalRecordIsUntouchedOnSuccess(): void
    {
        $writer = new TestHandler();

        $processor = new class implements ContractualProcessorInterface {
            public function allowedRecordPaths(): array
            {
                return ['extra'];
            }

            public function __invoke(LogRecord $record): LogRecord
            {
                return $record->with(extra: ['added' => true]);
            }
        };

        $logger = new Logger('channel', [$writer]);
        $logger->pushProcessor(new ContractChain([$processor]));

        $logger->info('hello', ['ctx' => 1]);

        $records = $writer->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(['ctx' => 1], $records[0]->context);
        $this->assertSame(['added' => true], $records[0]->extra);
        $this->assertSame('hello', $records[0]->message);
        $this->assertSame('channel', $records[0]->channel);
    }
}
