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

/**
 * Counting fixture: records how often close() and reset() were invoked.
 */
class CountingTestHandler extends TestHandler
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

/**
 * Fixture that always fails while writing a record.
 */
class ThrowingTestHandler extends TestHandler
{
    protected function write(LogRecord $record): void
    {
        throw new \RuntimeException('ThrowingTestHandler::write');
    }
}

class ChannelRoutingHandlerTest extends \Monolog\Test\MonologTestCase
{
    private function recordFor(string $channel, Level $level = Level::Warning): LogRecord
    {
        return $this->getRecord($level, 'test '.$channel, channel: $channel);
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::__construct
     */
    public function testConstructorRejectsInvalidRules()
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChannelRoutingHandler(['app' => 'not-a-handler']);
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::__construct
     */
    public function testConstructorRejectsEmptyPrefix()
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChannelRoutingHandler(['' => new TestHandler()]);
    }

    /**
     * Acceptance: app.db goes to the most specific target, app.web goes to
     * the app target, application falls through to the fallback.
     *
     * @covers Monolog\Handler\ChannelRoutingHandler::handle
     * @covers Monolog\Handler\ChannelRoutingHandler::getHandlerForChannel
     */
    public function testMostSpecificMatchAndFallback()
    {
        $db = new TestHandler();
        $app = new TestHandler();
        $fallback = new TestHandler();

        $handler = new ChannelRoutingHandler([
            'app' => $app,
            'app.db' => $db,
        ], $fallback);

        $this->assertSame($db, $handler->getHandlerForChannel('app.db'));
        $this->assertSame($app, $handler->getHandlerForChannel('app.web'));
        $this->assertSame($fallback, $handler->getHandlerForChannel('application'));

        $handler->handle($this->recordFor('app.db'));
        $handler->handle($this->recordFor('app.web'));
        $handler->handle($this->recordFor('application'));

        $this->assertCount(1, $db->getRecords());
        $this->assertSame('app.db', $db->getRecords()[0]->channel);
        $this->assertCount(1, $app->getRecords());
        $this->assertSame('app.web', $app->getRecords()[0]->channel);
        $this->assertCount(1, $fallback->getRecords());
        $this->assertSame('application', $fallback->getRecords()[0]->channel);
    }

    /**
     * Prefixes match on full channel segments only: `a` matches `a` and
     * `a.b` but not `ab`.
     *
     * @covers Monolog\Handler\ChannelRoutingHandler::handle
     */
    public function testSegmentBoundaries()
    {
        $a = new TestHandler();
        $fallback = new TestHandler();
        $handler = new ChannelRoutingHandler(['a' => $a], $fallback);

        $handler->handle($this->recordFor('a'));
        $handler->handle($this->recordFor('a.b'));
        $handler->handle($this->recordFor('ab'));

        $this->assertCount(2, $a->getRecords());
        $this->assertCount(1, $fallback->getRecords());
        $this->assertSame('ab', $fallback->getRecords()[0]->channel);
    }

    /**
     * Rules of equal specificity are resolved in declaration order.
     *
     * @covers Monolog\Handler\ChannelRoutingHandler::handle
     */
    public function testDeclarationOrderWinsForEqualSpecificity()
    {
        $first = new TestHandler();
        $second = new TestHandler();
        $handler = new ChannelRoutingHandler([
            ['app', $first],
            ['app', $second],
        ]);

        $handler->handle($this->recordFor('app'));

        $this->assertCount(1, $first->getRecords());
        $this->assertCount(0, $second->getRecords());
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::handle
     * @covers Monolog\Handler\ChannelRoutingHandler::isHandling
     */
    public function testNoMatchWithoutFallbackKeepsBubbling()
    {
        $handler = new ChannelRoutingHandler(['app' => new TestHandler()]);
        $record = $this->recordFor('other');

        $this->assertFalse($handler->isHandling($record));
        $this->assertFalse($handler->handle($record), 'unrouted records must not stop bubbling');
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::handle
     */
    public function testBubbleSemantics()
    {
        $routed = new TestHandler();
        $bubbling = new ChannelRoutingHandler(['app' => $routed], null, true);
        $notBubbling = new ChannelRoutingHandler(['app' => $routed], null, false);

        $this->assertFalse($bubbling->handle($this->recordFor('app')));
        $this->assertTrue($notBubbling->handle($this->recordFor('app')));
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::isHandling
     */
    public function testIsHandlingDelegatesToResolvedTarget()
    {
        $errorsOnly = new TestHandler(Level::Error);
        $handler = new ChannelRoutingHandler(['app' => $errorsOnly]);

        $this->assertFalse($handler->isHandling($this->recordFor('app', Level::Warning)));
        $this->assertTrue($handler->isHandling($this->recordFor('app', Level::Error)));
    }

    /**
     * A throwing target must propagate the exception and must NOT trigger
     * the fallback: the record is never re-dispatched, so it cannot be
     * written twice.
     *
     * @covers Monolog\Handler\ChannelRoutingHandler::handle
     */
    public function testExceptionDoesNotTriggerFallback()
    {
        $fallback = new TestHandler();
        $handler = new ChannelRoutingHandler(
            ['app' => new ThrowingTestHandler()],
            $fallback
        );

        try {
            $handler->handle($this->recordFor('app'));
            $this->fail('Exception of the routed handler should propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('ThrowingTestHandler::write', $e->getMessage());
        }

        $this->assertCount(0, $fallback->getRecords(), 'fallback must not receive records from a failing target');
    }

    /**
     * Acceptance: two rules sharing one handler must result in exactly one
     * close() (and reset()) call on the shared target.
     *
     * @covers Monolog\Handler\ChannelRoutingHandler::close
     * @covers Monolog\Handler\ChannelRoutingHandler::reset
     */
    public function testCloseAndResetCalledOncePerSharedHandler()
    {
        $shared = new CountingTestHandler();
        $fallback = new CountingTestHandler();

        $handler = new ChannelRoutingHandler([
            'app.db' => $shared,
            'app.web' => $shared,
        ], $fallback);

        $handler->close();
        $handler->reset();

        $this->assertSame(1, $shared->closeCount);
        $this->assertSame(1, $shared->resetCount);
        $this->assertSame(1, $fallback->closeCount);
        $this->assertSame(1, $fallback->resetCount);
    }

    /**
     * The rule snapshot taken at construction time cannot be mutated from
     * the outside.
     *
     * @covers Monolog\Handler\ChannelRoutingHandler::__construct
     * @covers Monolog\Handler\ChannelRoutingHandler::getHandlerForChannel
     */
    public function testRuleSnapshotIsImmutable()
    {
        $app = new TestHandler();
        $other = new TestHandler();

        $rules = ['app' => $app];
        $handler = new ChannelRoutingHandler($rules);

        // external mutation of the original array must not affect routing
        $rules['app'] = $other;
        $rules['other'] = $other;

        $this->assertSame($app, $handler->getHandlerForChannel('app'));
        $this->assertNull($handler->getHandlerForChannel('other'));
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::handleBatch
     */
    public function testHandleBatchRoutesPerRecord()
    {
        $db = new TestHandler();
        $app = new TestHandler();
        $fallback = new TestHandler();

        $handler = new ChannelRoutingHandler([
            'app' => $app,
            'app.db' => $db,
        ], $fallback);

        $handler->handleBatch([
            $this->recordFor('app.db'),
            $this->recordFor('app.web'),
            $this->recordFor('application'),
            $this->recordFor('app.db'),
        ]);

        $this->assertCount(2, $db->getRecords());
        $this->assertCount(1, $app->getRecords());
        $this->assertCount(1, $fallback->getRecords());
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::handle
     */
    public function testProcessorsAreAppliedBeforeRouting()
    {
        $app = new TestHandler();
        $handler = new ChannelRoutingHandler(['app' => $app]);
        $handler->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(extra: ['routed' => true] + $record->extra);
        });

        $handler->handle($this->recordFor('app'));

        $this->assertTrue($app->getRecords()[0]->extra['routed']);
    }
}
