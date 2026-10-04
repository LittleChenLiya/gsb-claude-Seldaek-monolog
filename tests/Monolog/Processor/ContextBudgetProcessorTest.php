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

class ContextBudgetProcessorTest extends \Monolog\Test\MonologTestCase
{
    public function testKeepsSmallFieldsAndDropsOversizedOneWithPathAndEstimatedBytes(): void
    {
        // "a" => "ab"  costs 1 (colon) + 1 (key) + 2 (value bytes) = 4
        // "b" => "xy"  costs 1 + 1 + 2 = 4
        // "big" => str_repeat('z', 20) costs 1 + 3 + 20 = 24
        $context = [
            'a' => 'ab',
            'b' => 'xy',
            'big' => str_repeat('z', 20),
        ];

        $processor = new ContextBudgetProcessor(8);
        $result = $processor($this->getRecord(context: $context));

        $this->assertSame(['a' => 'ab', 'b' => 'xy'], $result->context);
        $this->assertSame(8, $result->extra['context_budget']['budget']);
        $this->assertSame(8, $result->extra['context_budget']['used']);
        $this->assertSame(
            [['path' => 'big', 'bytes' => 24]],
            $result->extra['context_budget']['dropped']
        );
    }

    public function testCountsMultibyteStringsAsUtf8BytesNotCharacters(): void
    {
        // "你好" is 2 characters but 6 UTF-8 bytes (E4 BD A0 E5 A5 BD).
        $key = '键'; // 3 UTF-8 bytes
        $value = '你好'; // 6 UTF-8 bytes
        // entry cost = 1 colon + 3 key bytes + 6 value bytes = 10
        $record = $this->getRecord(context: [$key => $value]);

        // Fits exactly when the full 10 bytes are budgeted.
        $fits = (new ContextBudgetProcessor(10))($record);
        $this->assertSame([$key => $value], $fits->context);
        $this->assertArrayNotHasKey('context_budget', $fits->extra);

        // Counting characters (2 + 1 + 1 = 4) would wrongly let it fit at 9 bytes.
        $dropped = (new ContextBudgetProcessor(9))($record);
        $this->assertSame([], $dropped->context);
        $this->assertSame(
            [['path' => $key, 'bytes' => 10]],
            $dropped->extra['context_budget']['dropped']
        );
    }

    public function testNestedEntryIsRejectedWholeAndNeverPartiallyTruncated(): void
    {
        // "small" => 1 costs 1 + 5 + 1 = 7
        // "nested" => ["deep" => str_repeat('q', 10)] costs:
        //   1 colon + 6 key
        //   + 2 container + 1 colon + 4 inner key + 10 inner value = 17
        //   = 24 in total
        $nested = ['deep' => str_repeat('q', 10)];
        $context = ['small' => 1, 'nested' => $nested];

        $result = (new ContextBudgetProcessor(16))($this->getRecord(context: $context));

        $this->assertSame(['small' => 1], $result->context);
        $this->assertSame(
            [['path' => 'nested', 'bytes' => 24]],
            $result->extra['context_budget']['dropped']
        );
    }

    public function testNestedEntryFitsAsWholeAtExactBoundary(): void
    {
        // ["a", "b"] as a value: container 2 + separator 1 + 1 + 1 = 5
        // top-level entry "list" => [...] : 1 colon + 4 key + 5 = 10
        $context = ['list' => ['a', 'b']];

        $result = (new ContextBudgetProcessor(10))($this->getRecord(context: $context));

        $this->assertSame($context, $result->context);
        $this->assertArrayNotHasKey('context_budget', $result->extra);
    }

    public function testEntryExceedingBoundaryByOneByteIsDropped(): void
    {
        // "k" => "abc" costs 1 + 1 + 3 = 5
        $record = $this->getRecord(context: ['k' => 'abc']);

        $this->assertSame(['k' => 'abc'], (new ContextBudgetProcessor(5))($record)->context);

        $dropped = (new ContextBudgetProcessor(4))($record);
        $this->assertSame([], $dropped->context);
        $this->assertSame(0, $dropped->extra['context_budget']['used']);
        $this->assertSame(
            [['path' => 'k', 'bytes' => 5]],
            $dropped->extra['context_budget']['dropped']
        );
    }

    public function testEntryOrderIsPreservedAndLaterEntriesAreSkippedUntilOneFitsAgain(): void
    {
        // Costs: "ok" => "x" = 4; "huge" => 30 bytes = 35; "tail" => "y" = 6
        $context = [
            'ok' => 'x',
            'huge' => str_repeat('h', 30),
            'tail' => 'y',
        ];

        $result = (new ContextBudgetProcessor(10))($this->getRecord(context: $context));

        $this->assertSame(['ok' => 'x', 'tail' => 'y'], $result->context);
        $this->assertSame(
            [['path' => 'huge', 'bytes' => 35]],
            $result->extra['context_budget']['dropped']
        );
        $this->assertSame(10, $result->extra['context_budget']['used']);
    }

    public function testScalarCostsAreDeterministic(): void
    {
        // null=4, true=4, false=5, int -12 = 3, float 1.5 = 3
        // entries: 1 colon + 3 key bytes each => 8, 8, 9, 7, 7
        $context = [
            'nul' => null,
            'yes' => true,
            'not' => false,
            'num' => -12,
            'flt' => 1.5,
        ];

        $result = (new ContextBudgetProcessor(39))($this->getRecord(context: $context));

        $this->assertSame($context, $result->context);
        $this->assertArrayNotHasKey('context_budget', $result->extra);

        $droppedLast = (new ContextBudgetProcessor(38))($this->getRecord(context: $context));
        $this->assertSame(
            [['path' => 'flt', 'bytes' => 7]],
            $droppedLast->extra['context_budget']['dropped']
        );
    }

    public function testSuccessiveInvocationsDoNotShareAccumulatedState(): void
    {
        $processor = new ContextBudgetProcessor(6);

        // Each record: "k" => "abcd" costs 1 + 1 + 4 = 6 and fills the budget.
        $first = $processor($this->getRecord(context: ['k' => 'abcd']));
        $second = $processor($this->getRecord(context: ['k' => 'abcd']));
        $third = $processor($this->getRecord(context: ['k' => 'abcd']));

        $this->assertSame(['k' => 'abcd'], $first->context);
        $this->assertSame(['k' => 'abcd'], $second->context);
        $this->assertSame(['k' => 'abcd'], $third->context);
        $this->assertArrayNotHasKey('context_budget', $first->extra);
        $this->assertArrayNotHasKey('context_budget', $second->extra);
        $this->assertArrayNotHasKey('context_budget', $third->extra);

        // A dropped record must not poison the next one either.
        $dropped = $processor($this->getRecord(context: ['toolong' => str_repeat('z', 40)]));
        $this->assertSame([], $dropped->context);
        $after = $processor($this->getRecord(context: ['k' => 'abcd']));
        $this->assertSame(['k' => 'abcd'], $after->context);
        $this->assertArrayNotHasKey('context_budget', $after->extra);
    }

    public function testUnknownObjectsAreOpaqueLeavesAndNoUserCodeRuns(): void
    {
        $object = new ContextBudgetProcessorTestSpy();

        // "obj" => <stdClass-like spy>: 1 colon + 3 key + 8 prefix + class
        // length + 1 suffix
        $expectedClassBytes = strlen($object::class);
        $expectedCost = 1 + 3 + 8 + $expectedClassBytes + 1;

        $record = $this->getRecord(context: ['obj' => $object]);

        $fits = (new ContextBudgetProcessor($expectedCost))($record);
        $this->assertSame(['obj' => $object], $fits->context);
        $this->assertSame($object, $fits->context['obj']);

        $dropped = (new ContextBudgetProcessor($expectedCost - 1))($record);
        $this->assertSame([], $dropped->context);
        $this->assertSame(
            [['path' => 'obj', 'bytes' => $expectedCost]],
            $dropped->extra['context_budget']['dropped']
        );

        $this->assertSame(0, $object->jsonSerializeCalls);
        $this->assertSame(0, $object->toStringCalls);
    }

    public function testEnumsAndResourcesAreEstimatedSafely(): void
    {
        $resource = fopen('php://memory', 'r');
        $this->assertIsResource($resource);

        $context = [
            'enum' => ContextBudgetProcessorTestEnum::Hearts,
            'backed' => ContextBudgetProcessorTestBackedEnum::Two,
            'res' => $resource,
        ];

        // enum entry: 1 + 4 + strlen("Hearts") = 11
        // backed entry: 1 + 6 + strlen("2") = 8
        // resource entry: 1 + 3 + 10 + strlen("stream") + 1 = 21
        $result = (new ContextBudgetProcessor(40))($this->getRecord(context: $context));
        $this->assertSame($context, $result->context);

        $partial = (new ContextBudgetProcessor(19))($this->getRecord(context: $context));
        $this->assertSame(
            ['enum' => ContextBudgetProcessorTestEnum::Hearts, 'backed' => ContextBudgetProcessorTestBackedEnum::Two],
            $partial->context
        );
        $this->assertSame(
            [['path' => 'res', 'bytes' => 21]],
            $partial->extra['context_budget']['dropped']
        );

        fclose($resource);
    }

    public function testOriginalRecordIsNotMutatedAndUnchangedRecordIsReturnedAsIs(): void
    {
        $record = $this->getRecord(context: ['a' => 'b'], extra: ['pre' => 'existing']);
        $result = (new ContextBudgetProcessor(100))($record);

        $this->assertSame($record, $result);
        $this->assertSame(['pre' => 'existing'], $record->extra);

        $truncated = (new ContextBudgetProcessor(1))($this->getRecord(context: ['a' => 'bb'], extra: ['pre' => 'existing']));
        $this->assertNotSame($record, $truncated);
        $this->assertSame(['pre' => 'existing', 'context_budget' => [
            'budget' => 1,
            'used' => 0,
            'dropped' => [['path' => 'a', 'bytes' => 4]],
        ]], $truncated->extra);
        // original record stayed untouched
        $this->assertSame(['pre' => 'existing'], $record->extra);
        $this->assertSame(['a' => 'b'], $record->context);
    }

    public function testEmptyContextIsLeftUntouched(): void
    {
        $record = $this->getRecord(context: []);
        $this->assertSame($record, (new ContextBudgetProcessor(0))($record));
    }

    public function testNegativeBudgetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ContextBudgetProcessor(-1);
    }
}

class ContextBudgetProcessorTestSpy implements \JsonSerializable
{
    public int $jsonSerializeCalls = 0;
    public int $toStringCalls = 0;

    public function jsonSerialize(): mixed
    {
        $this->jsonSerializeCalls++;

        return ['should' => 'not be serialized'];
    }

    public function __toString(): string
    {
        $this->toStringCalls++;

        return 'should not be stringified';
    }
}

enum ContextBudgetProcessorTestEnum
{
    case Hearts;
    case Spades;
}

enum ContextBudgetProcessorTestBackedEnum: int
{
    case One = 1;
    case Two = 2;
}
