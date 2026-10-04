<?php declare(strict_types=1);

/*
 * Native PHP demo for Monolog\Processor\ContextBudgetProcessor.
 *
 * Run with production dependencies only:
 *   cd demo
 *   composer install --no-dev
 *   php context_budget.php
 */

require __DIR__.'/vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Processor\ContextBudgetProcessor;

$checks = 0;
$check = function (string $label, bool $ok): void {
    global $checks;
    $checks++;
    if ($ok) {
        echo "PASS: $label\n";
    } else {
        echo "FAIL: $label\n";
        exit(1);
    }
};

$record = static function (array $context): LogRecord {
    return new LogRecord(new DateTimeImmutable(), 'demo', Level::Warning, 'test message', $context);
};

// 1. Two small fields and one oversized field: keep the first two, drop the
//    big one with its path and estimated bytes.
$processor = new ContextBudgetProcessor(64);
$result = $processor($record([
    'small_one' => 'hello',
    'small_two' => 'world',
    'big_field' => str_repeat('x', 200),
]));

$check('two small fields kept in original order', array_keys($result->context) === ['small_one', 'small_two', '_budget']);
$check('oversized field dropped with path and estimated bytes',
    isset($result->context['_budget']['dropped_bytes']['big_field'])
    && $result->context['_budget']['dropped_bytes']['big_field'] === 2 + 9 + 1 + (2 + 200) + 1);
$check('summary reports used and budget bytes',
    $result->context['_budget']['budget_bytes'] === 64
    && $result->context['_budget']['used_bytes'] === 2 + 20 + 20);

// 2. Chinese is counted in UTF-8 bytes, not characters: "中文" is 6 bytes.
$check('chinese string is 6 UTF-8 bytes, not 2 characters', strlen('中文') === 6);
$cnEntryCost = 2 + 2 + 1 + (2 + 6) + 1; // "cn" => "中文"
$result = (new ContextBudgetProcessor(2 + $cnEntryCost))($record(['cn' => '中文']));
$check('chinese entry kept when budget fits its UTF-8 byte cost', array_keys($result->context) === ['cn']);

$result = (new ContextBudgetProcessor(2 + $cnEntryCost - 1))($record(['cn' => '中文']));
$check('chinese entry dropped when one byte short',
    isset($result->context['_budget']['dropped_bytes']['cn'])
    && $result->context['_budget']['dropped_bytes']['cn'] === $cnEntryCost);

// 3. Nested entries are kept or rejected as a whole - no half values.
$nested = ['keep_me' => 'a', 'drop_me_too' => str_repeat('y', 500)];
$result = (new ContextBudgetProcessor(40))($record(['small' => 'x', 'nested' => $nested]));
$check('nested entry rejected as a whole when it does not fit',
    isset($result->context['small']) && !isset($result->context['nested'])
    && isset($result->context['_budget']['dropped_bytes']['nested']));

$smallNested = ['a' => 'b', 'c' => 'd'];
$result = (new ContextBudgetProcessor(4096))($record(['small' => 'x', 'nested' => $smallNested]));
$check('nested entry kept as a whole when it fits',
    $result->context['nested'] === $smallNested && !isset($result->context['_budget']));

// 4. Boundary: an entry whose cost exactly equals the remaining budget is
//    kept; one byte more is dropped.
$entryCost = 2 + 1 + 1 + 2 + 1 + 1; // "k":"v" including key quotes, colon and comma
$result = (new ContextBudgetProcessor(2 + $entryCost))($record(['k' => 'v']));
$check('exact-fit boundary is kept', array_keys($result->context) === ['k']);

$result = (new ContextBudgetProcessor(2 + $entryCost - 1))($record(['k' => 'v']));
$check('one byte over the boundary is dropped', isset($result->context['_budget']['dropped_bytes']['k']));

// 5. Consecutive calls on the same processor instance never share state.
$shared = new ContextBudgetProcessor(64);
$first = $shared($record(['a' => str_repeat('a', 200)]));
$second = $shared($record(['b' => 'small']));
$check('first record has the drop summary', isset($first->context['_budget']['dropped_bytes']['a']));
$check('second record has no leftover drop summary', !isset($second->context['_budget']) && array_keys($second->context) === ['b']);
$check('first record was not mutated by the second call', isset($first->context['_budget']) && !isset($first->context['b']));

// 6. Unknown objects never execute user code (__toString is not called).
$object = new class {
    public bool $toStringCalled = false;
    public function __toString(): string
    {
        $this->toStringCalled = true;
        return 'user-code-ran';
    }
};
$result = (new ContextBudgetProcessor(4096))($record(['obj' => $object]));
$check('unknown object kept as-is without running __toString',
    $result->context['obj'] === $object && $object->toStringCalled === false);

// 7. The original record is not mutated; a new record is returned.
$context = ['a' => 'b', 'big' => str_repeat('z', 500)];
$orig = $record($context);
$result = (new ContextBudgetProcessor(32))($orig);
$check('original record untouched, new record returned',
    $orig !== $result && $orig->context === $context && !isset($result->context['big']));

// 8. End-to-end: a Logger with the processor keeps the rendered line bounded.
$logger = new Logger('demo');
$logger->pushProcessor(new ContextBudgetProcessor(128));
$stream = fopen('php://memory', 'r+');
$logger->pushHandler(new StreamHandler($stream));
$logger->warning('User {user} uploaded a file', [
    'user' => 'alice',
    'metadata' => str_repeat('m', 2000),
]);
rewind($stream);
$line = stream_get_contents($stream);
$check('logger renders a bounded line with the drop summary',
    str_contains($line, '_budget') && str_contains($line, 'metadata') && str_contains($line, 'dropped_bytes'));

echo "\nAll $checks checks passed.\n";
exit(0);
