<?php declare(strict_types=1);

/*
 * Native (PHPUnit-free) test for Monolog\Handler\ChannelRoutingHandler.
 *
 * Run with: php tests/native/channel_routing_handler_native_test.php
 *
 * Uses only production dependencies (monolog + psr/log), TestHandler and
 * counting fixtures. No external services are contacted.
 */

use Monolog\Handler\ChannelRoutingHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

require __DIR__.'/autoload.php';

/** Counting fixture: records close()/reset() invocations. */
class NativeCountingHandler extends TestHandler
{
    public int $closeCount = 0;
    public int $resetCount = 0;

    public function close(): void
    {
        $this->closeCount++;
        parent::close();
    }

    public function reset(): void
    {
        $this->resetCount++;
        parent::reset();
    }
}

/** Fixture that always fails while writing. */
class NativeThrowingHandler extends TestHandler
{
    protected function write(LogRecord $record): void
    {
        throw new RuntimeException('target write failed');
    }
}

$failures = 0;
$checks = 0;
function check(bool $condition, string $label): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$label}\n");
    } else {
        echo "ok: {$label}\n";
    }
}

function nativeRecord(string $channel): LogRecord
{
    return new LogRecord(new DateTimeImmutable(), $channel, Level::Warning, 'msg '.$channel);
}

// --- Acceptance: app.db -> most specific, app.web -> app, application -> fallback
$db = new TestHandler();
$app = new TestHandler();
$fallback = new TestHandler();
$routing = new ChannelRoutingHandler(['app' => $app, 'app.db' => $db], $fallback);

$routing->handle(nativeRecord('app.db'));
$routing->handle(nativeRecord('app.web'));
$routing->handle(nativeRecord('application'));

check(1 === \count($db->getRecords()) && 'app.db' === $db->getRecords()[0]->channel, 'app.db routed to most specific target');
check(1 === \count($app->getRecords()) && 'app.web' === $app->getRecords()[0]->channel, 'app.web routed to app target');
check(1 === \count($fallback->getRecords()) && 'application' === $fallback->getRecords()[0]->channel, 'application routed to fallback');

// --- Full-segment prefix matching: a matches a and a.b but not ab
$a = new TestHandler();
$fb2 = new TestHandler();
$routing2 = new ChannelRoutingHandler(['a' => $a], $fb2);
$routing2->handle(nativeRecord('a'));
$routing2->handle(nativeRecord('a.b'));
$routing2->handle(nativeRecord('ab'));
check(2 === \count($a->getRecords()), '"a" matches "a" and "a.b"');
check(1 === \count($fb2->getRecords()) && 'ab' === $fb2->getRecords()[0]->channel, '"a" does not match "ab"');

// --- Same specificity: declaration order wins (pair form)
$first = new TestHandler();
$second = new TestHandler();
$routing3 = new ChannelRoutingHandler([['app', $first], ['app', $second]]);
$routing3->handle(nativeRecord('app'));
check(1 === \count($first->getRecords()) && 0 === \count($second->getRecords()), 'equal specificity: first declaration wins');

// --- Bubble semantics
$bubbling = new ChannelRoutingHandler(['app' => new TestHandler()], null, true);
$notBubbling = new ChannelRoutingHandler(['app' => new TestHandler()], null, false);
check(false === $bubbling->handle(nativeRecord('app')), 'routed record bubbles when bubble=true');
check(true === $notBubbling->handle(nativeRecord('app')), 'routed record stops bubbling when bubble=false');
$noRoute = new ChannelRoutingHandler(['app' => new TestHandler()]);
check(false === $noRoute->handle(nativeRecord('other')), 'unrouted record (no fallback) keeps bubbling');
check(false === $noRoute->isHandling(nativeRecord('other')), 'isHandling false without route and fallback');

// --- Throwing target: exception propagates, fallback is NOT triggered
$fb3 = new TestHandler();
$routing4 = new ChannelRoutingHandler(['app' => new NativeThrowingHandler()], $fb3);
$thrown = false;
try {
    $routing4->handle(nativeRecord('app'));
} catch (RuntimeException $e) {
    $thrown = true;
}
check($thrown, 'exception of routed target propagates');
check(0 === \count($fb3->getRecords()), 'fallback not triggered after target failure (no duplicate write)');

// --- Acceptance: shared handler closed/reset exactly once
$shared = new NativeCountingHandler();
$fb4 = new NativeCountingHandler();
$routing5 = new ChannelRoutingHandler(['app.db' => $shared, 'app.web' => $shared], $fb4);
$routing5->close();
$routing5->reset();
check(1 === $shared->closeCount, 'shared target closed exactly once');
check(1 === $shared->resetCount, 'shared target reset exactly once');
check(1 === $fb4->closeCount && 1 === $fb4->resetCount, 'fallback closed/reset exactly once');

// --- Rule snapshot cannot be mutated from outside
$orig = new TestHandler();
$other = new TestHandler();
$rules = ['app' => $orig];
$routing6 = new ChannelRoutingHandler($rules);
$rules['app'] = $other;
$rules['other'] = $other;
check($orig === $routing6->getHandlerForChannel('app'), 'rule snapshot immutable: external replacement ignored');
check(null === $routing6->getHandlerForChannel('other'), 'rule snapshot immutable: external addition ignored');

// --- End-to-end through one Logger stack shared by several channels
$shared2 = new TestHandler();
$routing7 = new ChannelRoutingHandler(['app' => $shared2]);
foreach (['app.db', 'app.web'] as $channel) {
    $logger = new Logger($channel);
    $logger->pushHandler($routing7);
    $logger->warning('hello from '.$channel);
}
check(2 === \count($shared2->getRecords()), 'records from several loggers routed through one handler instance');

echo "---\n";
if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} checks FAILED\n");
    exit(1);
}
echo "All {$checks} checks passed\n";
