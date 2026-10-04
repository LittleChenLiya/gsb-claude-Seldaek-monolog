<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Standalone demo for Monolog\Processor\DuplicateCountProcessor.
 *
 * It needs no composer install and no vendor directory: a tiny autoloader
 * for the Monolog sources in ../src is registered below. Run it with:
 *
 *     php examples/duplicate_count_processor_demo.php
 *
 * Every scenario prints the sequence numbers the processor assigned and
 * self-checks them against the expected values, so the script doubles as
 * a lightweight acceptance run.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Monolog\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__.'/../src/Monolog/'.str_replace('\\', '/', substr($class, \strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\DuplicateCountProcessor;

function record(string $message = 'job failed', array $context = [], Level $level = Level::Error, string $channel = 'worker'): LogRecord
{
    return new LogRecord(new DateTimeImmutable(), $channel, $level, $message, $context);
}

function seq(LogRecord $record): int
{
    return $record->extra['duplicate_count'];
}

function check(string $label, array $actual, array $expected): void
{
    $ok = $actual === $expected;
    printf("%-64s got [%s] expected [%s] %s\n", $label, implode(', ', $actual), implode(', ', $expected), $ok ? 'OK' : 'FAIL');
    if (!$ok) {
        throw new RuntimeException('Scenario failed: '.$label);
    }
}

// 1. Same identity logged twice in a row is numbered 1, 2 - nothing is dropped.
$p = new DuplicateCountProcessor();
check('same identity twice in a row', [
    seq($p(record())), seq($p(record())),
], [1, 2]);

// 2. Selected context fields are matched by type: int 1 vs string "1".
$p = new DuplicateCountProcessor(['attempt']);
check('context int 1 then string "1" restarts at 1', [
    seq($p(record(context: ['attempt' => 1]))),
    seq($p(record(context: ['attempt' => '1']))),
    seq($p(record(context: ['attempt' => 1]))),
], [1, 1, 2]);

// 3. Missing field and explicit null share one "nil" identity.
$p = new DuplicateCountProcessor(['attempt']);
check('missing field and null share the nil identity', [
    seq($p(record(context: []))),
    seq($p(record(context: ['attempt' => null]))),
], [1, 2]);

// 4. Level is part of the identity.
$p = new DuplicateCountProcessor();
check('same message at different levels counts separately', [
    seq($p(record(level: Level::Error))),
    seq($p(record(level: Level::Warning))),
    seq($p(record(level: Level::Error))),
], [1, 1, 2]);

// 5. Bounded cache: with capacity 2 the third identity evicts the LRU one.
$p = new DuplicateCountProcessor([], 2);
check('capacity 2 evicts the least recently used identity', [
    seq($p(record(message: 'a'))),          // a:1
    seq($p(record(message: 'b'))),          // b:1
    seq($p(record(message: 'a'))),          // a:2 (a is now most recently used)
    seq($p(record(message: 'c'))),          // c:1, evicts b (the LRU entry)
    seq($p(record(message: 'a'))),          // a kept its count: 3
    seq($p(record(message: 'b'))),          // b was evicted: back to 1
], [1, 1, 2, 1, 3, 1]);

// 6. reset() empties the cache; the first record after reset is 1 again.
$p = new DuplicateCountProcessor();
$before = [seq($p(record())), seq($p(record()))];
$p->reset();
$after = [seq($p(record())), seq($p(record()))];
check('reset clears counts, first record is 1 again', array_merge($before, $after), [1, 2, 1, 2]);

// 7. Unknown objects are rejected as identity, never serialized.
$p = new DuplicateCountProcessor(['payload']);
try {
    $p(record(context: ['payload' => new stdClass()]));
    check('object context value is rejected', ['no exception'], ['InvalidArgumentException']);
} catch (InvalidArgumentException $e) {
    check('object context value is rejected', ['InvalidArgumentException'], ['InvalidArgumentException']);
    echo '    -> '.$e->getMessage()."\n";
}

// 8. The original record is left untouched; the count arrives on a copy.
$p = new DuplicateCountProcessor();
$original = record();
$processed = $p($original);
check('original record is not modified', [
    $original->extra === [] ? 'untouched' : 'mutated',
    $processed === $original ? 'same instance' : 'copy',
    seq($processed),
], ['untouched', 'copy', 1]);

echo "\nAll demo scenarios passed.\n";
