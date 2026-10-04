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

use Monolog\Test\MonologTestCase;

/**
 * @covers Monolog\Processor\ContextBudgetProcessor
 */
class ContextBudgetProcessorTest extends MonologTestCase
{
    public function testKeepsSmallFieldsAndDropsOversizedOneWithSummary()
    {
        $processor = new ContextBudgetProcessor(64);
        $record = $this->getRecord(context: [
            'small_one' => 'hello',
            'small_two' => 'world',
            'big_field' => \str_repeat('x', 200),
        ]);

        $result = $processor($record);

        $this->assertSame(['small_one', 'small_two', '_budget'], \array_keys($result->context));
        $this->assertSame('hello', $result->context['small_one']);
        $this->assertSame('world', $result->context['small_two']);
        $this->assertArrayNotHasKey('big_field', $result->context);

        $this->assertSame(64, $result->context['_budget']['budget_bytes']);
        $this->assertSame(['big_field'], \array_keys($result->context['_budget']['dropped_bytes']));
        // entryCost = 2 key quotes + 9 key bytes + 1 colon + (2 + 200) value + 1 comma
        $this->assertSame(2 + 9 + 1 + (2 + 200) + 1, $result->context['_budget']['dropped_bytes']['big_field']);
        // used_bytes = 2 container + 2 entries of 20 bytes each
        $this->assertSame(2 + 20 + 20, $result->context['_budget']['used_bytes']);
    }

    public function testChineseCharactersCountAsUtf8BytesNotCharacters()
    {
        $this->assertSame(6, \strlen('中文'));

        // "cn" => "中文" costs: 2 key quotes + 2 key bytes + 1 colon + (2 + 6) value + 1 comma = 14
        $fits = new ContextBudgetProcessor(2 + 14);
        $this->assertSame(['cn'], \array_keys($fits($this->getRecord(context: ['cn' => '中文']))->context));

        $tooSmall = new ContextBudgetProcessor(2 + 14 - 1);
        $result = $tooSmall($this->getRecord(context: ['cn' => '中文']));
        $this->assertArrayHasKey('_budget', $result->context);
        $this->assertSame(['cn'], \array_keys($result->context['_budget']['dropped_bytes']));
        $this->assertSame(14, $result->context['_budget']['dropped_bytes']['cn']);
    }

    public function testNestedValueIsRejectedAsAWholeNotHalfKept()
    {
        $nested = [
            'keep_me' => 'a',
            'drop_me_too' => \str_repeat('y', 500),
        ];
        $processor = new ContextBudgetProcessor(40);
        $result = $processor($this->getRecord(context: [
            'small' => 'x',
            'nested' => $nested,
        ]));

        $this->assertArrayHasKey('small', $result->context);
        $this->assertArrayNotHasKey('nested', $result->context);
        $this->assertSame(['nested'], \array_keys($result->context['_budget']['dropped_bytes']));
        // arrayCost = 2 container + (2 + 7 + 1 + 3 + 1) + (2 + 11 + 1 + 502 + 1) = 533
        // entryCost = 2 key quotes + 6 key bytes + 1 colon + 533 + 1 comma = 543
        $this->assertSame(2 + 6 + 1 + (2 + (2 + 7 + 1 + (2 + 1) + 1) + (2 + 11 + 1 + (2 + 500) + 1)) + 1, $result->context['_budget']['dropped_bytes']['nested']);
    }

    public function testNestedValueKeptWholeWhenItFits()
    {
        $nested = ['a' => 'b', 'c' => 'd'];
        $processor = new ContextBudgetProcessor(4096);
        $result = $processor($this->getRecord(context: [
            'small' => 'x',
            'nested' => $nested,
        ]));

        $this->assertSame(['small', 'nested'], \array_keys($result->context));
        $this->assertSame($nested, $result->context['nested']);
        $this->assertArrayNotHasKey('_budget', $result->context);
    }

    public function testBoundaryExactFitIsKeptOneByteLessIsDropped()
    {
        $entryCost = 2 + 1 + 1 + 2 + 1 + 1;

        $exact = new ContextBudgetProcessor(2 + $entryCost);
        $result = $exact($this->getRecord(context: ['k' => 'v']));
        $this->assertSame(['k'], \array_keys($result->context));

        $oneShort = new ContextBudgetProcessor(2 + $entryCost - 1);
        $result = $oneShort($this->getRecord(context: ['k' => 'v']));
        $this->assertArrayHasKey('_budget', $result->context);
        $this->assertSame(['k'], \array_keys($result->context['_budget']['dropped_bytes']));
    }

    public function testConsecutiveCallsDoNotShareAccumulatedState()
    {
        $processor = new ContextBudgetProcessor(64);

        $first = $processor($this->getRecord(context: [
            'a' => \str_repeat('a', 200),
        ]));
        $this->assertArrayHasKey('_budget', $first->context);
        $this->assertSame(['a'], \array_keys($first->context['_budget']['dropped_bytes']));

        $second = $processor($this->getRecord(context: [
            'b' => 'small',
        ]));
        $this->assertArrayNotHasKey('_budget', $second->context);
        $this->assertSame(['b'], \array_keys($second->context));

        $this->assertArrayHasKey('_budget', $first->context);
        $this->assertArrayNotHasKey('b', $first->context);
    }

    public function testUnknownObjectIsCostedWithoutExecutingUserCode()
    {
        $object = new class {
            public bool $toStringCalled = false;

            public function __toString(): string
            {
                $this->toStringCalled = true;

                return 'user-code-ran';
            }
        };

        $processor = new ContextBudgetProcessor(4096);
        $result = $processor($this->getRecord(context: ['obj' => $object]));

        $this->assertArrayHasKey('obj', $result->context);
        $this->assertSame($object, $result->context['obj']);
        $this->assertFalse($object->toStringCalled, '__toString() must not be executed');

        $fqcn = \Monolog\Utils::getClass($object);
        $this->assertSame('class@anonymous', $fqcn); // declaration site stripped, no user code run
        $expectedCost = 2 + 3 + 1 + (2 + \strlen('[object '.$fqcn.']')) + 1;
        $this->assertSame($expectedCost, $this->invokeEntryCost($processor, 'obj', $object));
    }

    public function testJsonSerializableObjectIsNotSerialized()
    {
        $object = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('jsonSerialize must not be called');
            }
        };

        $processor = new ContextBudgetProcessor(4096);
        $result = $processor($this->getRecord(context: ['obj' => $object]));

        $this->assertArrayHasKey('obj', $result->context);
    }

    public function testOriginalRecordIsNotMutatedAndANewRecordIsReturned()
    {
        $context = ['a' => 'b', 'big' => \str_repeat('z', 500)];
        $record = $this->getRecord(context: $context);
        $processor = new ContextBudgetProcessor(32);

        $result = $processor($record);

        $this->assertNotSame($record, $result);
        $this->assertSame($context, $record->context);
        $this->assertArrayHasKey('big', $record->context);
        $this->assertArrayNotHasKey('big', $result->context);
    }

    public function testScalarCosting()
    {
        $processor = new ContextBudgetProcessor(4096);

        // valueCost() returns the raw JSON-encoded value cost (string quotes included)
        $this->assertSame(4, $this->invokeValueCost($processor, null));       // null
        $this->assertSame(4, $this->invokeValueCost($processor, true));       // true
        $this->assertSame(5, $this->invokeValueCost($processor, false));      // false
        $this->assertSame(1, $this->invokeValueCost($processor, 1));          // 1
        $this->assertSame(3, $this->invokeValueCost($processor, 1.5));        // 1.5
        $this->assertSame(2 + 3, $this->invokeValueCost($processor, 'abc'));  // "abc"
    }

    public function testBackedEnumIsCostedAsItsBackingValue()
    {
        $processor = new ContextBudgetProcessor(4096);
        $enum = TestContextBudgetBackedEnum::Foo;

        $this->assertSame(2 + 3, $this->invokeValueCost($processor, $enum));
    }

    public function testUnitEnumIsCostedByName()
    {
        $processor = new ContextBudgetProcessor(4096);
        $enum = TestContextBudgetUnitEnum::Bar;

        $this->assertSame(2 + 3, $this->invokeValueCost($processor, $enum));
    }

    public function testCustomSummaryKey()
    {
        $processor = new ContextBudgetProcessor(32, '_truncated');
        $result = $processor($this->getRecord(context: ['big' => \str_repeat('x', 100)]));

        $this->assertArrayHasKey('_truncated', $result->context);
        $this->assertArrayNotHasKey('_budget', $result->context);
    }

    public function testAllEntriesDroppedWhenBudgetIsTiny()
    {
        $processor = new ContextBudgetProcessor(1);
        $result = $processor($this->getRecord(context: ['a' => 'b', 'c' => 'd']));

        $this->assertSame(['_budget'], \array_keys($result->context));
        $this->assertSame(['a', 'c'], \array_keys($result->context['_budget']['dropped_bytes']));
    }

    private function invokeValueCost(ContextBudgetProcessor $processor, mixed $value): int
    {
        $method = new \ReflectionMethod($processor, 'valueCost');

        return $method->invoke($processor, $value);
    }

    private function invokeEntryCost(ContextBudgetProcessor $processor, int|string $key, mixed $value): int
    {
        $method = new \ReflectionMethod($processor, 'entryCost');

        return $method->invoke($processor, $key, $value);
    }
}

enum TestContextBudgetBackedEnum: string
{
    case Foo = 'foo';
}

enum TestContextBudgetUnitEnum
{
    case Bar;
}
