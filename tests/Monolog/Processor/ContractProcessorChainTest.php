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

use Monolog\LogRecord;
use Monolog\Test\MonologTestCase;

class ContractChainCustomValue
{
    public function __construct(public string $state)
    {
    }
}

class ContractProcessorChainTest extends MonologTestCase
{
    public function testLegalModificationWithinContractPasses(): void
    {
        $chain = new ContractProcessorChain();
        $chain->push(function (LogRecord $record): LogRecord {
            $record->extra['uid'] = 'abc123';

            return $record;
        }, ProcessorContract::allowing('extra'));

        $record = $this->getRecord(extra: ['keep' => 'me']);
        $result = $chain->process($record);

        $this->assertTrue($result->isOk());
        $this->assertNull($result->getViolation());
        $this->assertSame('abc123', $result->getRecord()->extra['uid']);
        $this->assertSame('me', $result->getRecord()->extra['keep']);
        // the original record is not the processed one and stays untouched
        $this->assertNotSame($record, $result->getRecord());
        $this->assertSame(['keep' => 'me'], $record->extra);
    }

    public function testOutOfContractContextModificationIsRejected(): void
    {
        $chain = new ContractProcessorChain();
        $chain->push(function (LogRecord $record): LogRecord {
            // contract allows extra only, sneaking a context change in
            return $record->with(context: ['injected' => true]);
        }, ProcessorContract::allowing('extra'));

        $record = $this->getRecord(context: ['original' => 'context']);
        $result = $chain->process($record);

        $this->assertFalse($result->isOk());
        $violation = $result->getViolation();
        $this->assertNotNull($violation);
        $this->assertSame(ContractViolation::REASON_OUT_OF_CONTRACT_MODIFICATION, $violation->reason);
        $this->assertSame('context.injected', $violation->path);
        $this->assertSame(0, $violation->processorIndex);
        // the original record is returned untouched
        $this->assertSame($record, $result->getRecord());
        $this->assertSame(['original' => 'context'], $record->context);
    }

    public function testNestedContractPathsAreEnforced(): void
    {
        $chain = new ContractProcessorChain();
        $chain->push(function (LogRecord $record): LogRecord {
            $record->extra['uid'] = 'ok';
            $record->extra['other'] = 'not-ok';

            return $record;
        }, ProcessorContract::allowing('extra.uid'));

        $result = $chain->process($this->getRecord());

        $this->assertFalse($result->isOk());
        $this->assertSame('extra.other', $result->getViolation()->path);

        $chain = new ContractProcessorChain();
        $chain->push(function (LogRecord $record): LogRecord {
            $record->extra['uid'] = 'ok';

            return $record;
        }, ProcessorContract::allowing('extra.uid'));

        $this->assertTrue($chain->process($this->getRecord())->isOk());
    }

    public function testUnknownObjectWithoutVerifierIsNotInspectedAndBlocksCallback(): void
    {
        $value = new class {
            public bool $getterCalled = false;

            public function getState(): string
            {
                $this->getterCalled = true;

                return 'state';
            }
        };

        $called = 0;
        $chain = new ContractProcessorChain();
        $chain->push(function (LogRecord $record) use (&$called): LogRecord {
            $called++;
            $record->extra['x'] = 1;

            return $record;
        }, ProcessorContract::allowing('extra'));

        $record = $this->getRecord(context: ['custom' => $value]);
        $result = $chain->process($record);

        // the custom object has no registered verification means: the callback never runs
        $this->assertSame(0, $called);
        $this->assertFalse($result->isOk());
        $violation = $result->getViolation();
        $this->assertSame(ContractViolation::REASON_NON_VERIFIABLE_VALUE, $violation->reason);
        $this->assertSame('context.custom', $violation->path);
        // and the object was never inspected through its getter
        $this->assertFalse($value->getterCalled);
        $this->assertSame($record, $result->getRecord());
    }

    public function testUnknownObjectWithRegisteredVerifierPassesAndIsVerified(): void
    {
        $chain = new ContractProcessorChain();
        $chain->registerVerifier(ContractChainCustomValue::class, fn (ContractChainCustomValue $v): array => ['state' => $v->state]);
        $chain->push(function (LogRecord $record): LogRecord {
            $record->extra['x'] = 1;

            return $record;
        }, ProcessorContract::allowing('extra'));

        $record = $this->getRecord(context: ['custom' => new ContractChainCustomValue('initial')]);
        $result = $chain->process($record);
        $this->assertTrue($result->isOk());
        $this->assertSame(1, $result->getRecord()->extra['x']);

        // mutating the custom object outside the contract is caught by the verifier
        $chain = new ContractProcessorChain();
        $chain->registerVerifier(ContractChainCustomValue::class, fn (ContractChainCustomValue $v): array => ['state' => $v->state]);
        $chain->push(function (LogRecord $record): LogRecord {
            $custom = $record->context['custom'];
            $custom->state = 'tampered';

            return $record;
        }, ProcessorContract::allowing('extra'));

        $original = new ContractChainCustomValue('initial');
        $record = $this->getRecord(context: ['custom' => $original]);
        $result = $chain->process($record);

        $this->assertFalse($result->isOk());
        $this->assertSame('context.custom.state', $result->getViolation()->path);
        // the candidate was isolated: the original object was never mutated
        $this->assertSame('initial', $original->state);
    }

    public function testArrayReferencesAreIsolatedFromTheOriginalRecord(): void
    {
        $bag = ['count' => 1];
        $extra = [];
        // a shared array reference inside extra
        $extra['bag'] = &$bag;

        $chain = new ContractProcessorChain();
        $chain->push(function (LogRecord $record): LogRecord {
            $record->extra['bag']['count'] = 99;
            $record->extra['bag']['added'] = true;

            return $record;
        }, ProcessorContract::allowing('extra'));

        $record = $this->getRecord(extra: $extra);
        $result = $chain->process($record);

        $this->assertTrue($result->isOk());
        $this->assertSame(99, $result->getRecord()->extra['bag']['count']);
        $this->assertTrue($result->getRecord()->extra['bag']['added']);
        // neither the shared variable nor the original record were touched
        $this->assertSame(['count' => 1], $bag);
        $this->assertSame(1, $record->extra['bag']['count']);
        $this->assertArrayNotHasKey('added', $record->extra['bag']);
    }

    public function testOrderAndCallbackCountsFollowTheOriginalChain(): void
    {
        $calls = [];
        $processor = function (string $name, callable $fn) use (&$calls) {
            return function (LogRecord $record) use ($name, $fn, &$calls): LogRecord {
                $calls[] = $name;

                return $fn($record);
            };
        };

        $chain = new ContractProcessorChain();
        $chain->push($processor('A', fn (LogRecord $r) => $r), ProcessorContract::allowing('extra'));
        $chain->push($processor('B', fn (LogRecord $r) => $r), ProcessorContract::allowing('extra'));
        $chain->push($processor('C', fn (LogRecord $r) => $r), ProcessorContract::allowing('extra'));

        $result = $chain->process($this->getRecord());

        // same LIFO order as the default processor stack, each callback exactly once
        $this->assertSame(['C', 'B', 'A'], $calls);
        $this->assertTrue($result->isOk());
    }

    public function testChainAbortsOnFirstViolationWithoutCallingRemainingProcessors(): void
    {
        $calls = [];
        $processor = function (string $name, callable $fn) use (&$calls) {
            return function (LogRecord $record) use ($name, $fn, &$calls): LogRecord {
                $calls[] = $name;

                return $fn($record);
            };
        };

        $chain = new ContractProcessorChain();
        $chain->push($processor('A', fn (LogRecord $r) => $r), ProcessorContract::allowing('extra'));
        $chain->push($processor('B', fn (LogRecord $r) => $r->with(message: 'hacked')), ProcessorContract::allowing('extra'));
        $chain->push($processor('C', fn (LogRecord $r) => $r), ProcessorContract::allowing('extra'));

        $record = $this->getRecord();
        $result = $chain->process($record);

        // C ran, B violated, A was never called
        $this->assertSame(['C', 'B'], $calls);
        $this->assertFalse($result->isOk());
        $this->assertSame(1, $result->getViolation()->processorIndex);
        $this->assertSame('message', $result->getViolation()->path);
        // no half-processed record: the untouched original is returned
        $this->assertSame($record, $result->getRecord());
        $this->assertSame('test', $record->message);
    }

    public function testFailedChainLeavesOriginalRecordUntouchedEvenWithInPlaceMutations(): void
    {
        $chain = new ContractProcessorChain();
        $chain->push(function (LogRecord $record): LogRecord {
            // legal in-place mutation of the candidate...
            $record->extra['uid'] = 'abc';
            // ...plus an out-of-contract one
            $record->formatted = 'tampered';

            return $record;
        }, ProcessorContract::allowing('extra'));

        $record = $this->getRecord(extra: ['keep' => 'me']);
        $result = $chain->process($record);

        $this->assertFalse($result->isOk());
        $this->assertSame('formatted', $result->getViolation()->path);
        $this->assertSame(['keep' => 'me'], $record->extra);
        $this->assertNull($record->formatted);
    }

    public function testContractedProcessorCarriesItsOwnContract(): void
    {
        $chain = new ContractProcessorChain();
        $chain->push(new ContractedProcessor(function (LogRecord $record): LogRecord {
            $record->extra['uid'] = 'abc';

            return $record;
        }, ProcessorContract::allowing('extra')));

        $this->assertTrue($chain->process($this->getRecord())->isOk());
    }

    public function testPushRequiresAContract(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $chain = new ContractProcessorChain();
        $chain->push(fn (LogRecord $record): LogRecord => $record);
    }

    public function testContractRejectsUnknownTopLevelPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProcessorContract::allowing('unknown_field');
    }
}
