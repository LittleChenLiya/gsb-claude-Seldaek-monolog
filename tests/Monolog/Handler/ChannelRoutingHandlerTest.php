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

use Monolog\Handler\Fixtures\CountingHandler;
use Monolog\Level;

class ChannelRoutingHandlerTest extends \Monolog\Test\MonologTestCase
{
    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::handle
     * @covers Monolog\Handler\ChannelRoutingHandler::getHandlerForChannel
     */
    public function testRoutesToMostSpecificTargetAndFallback()
    {
        $dbHandler = new TestHandler();
        $appHandler = new TestHandler();
        $fallbackHandler = new TestHandler();

        $router = new ChannelRoutingHandler([
            'app' => $appHandler,
            'app.db' => $dbHandler,
        ], $fallbackHandler);

        $router->handle($this->getRecord(Level::Warning, 'db query', channel: 'app.db'));
        $router->handle($this->getRecord(Level::Warning, 'web request', channel: 'app.web'));
        $router->handle($this->getRecord(Level::Warning, 'generic', channel: 'application'));

        $this->assertCount(1, $dbHandler->getRecords());
        $this->assertSame('db query', $dbHandler->getRecords()[0]->message);
        $this->assertCount(1, $appHandler->getRecords());
        $this->assertSame('web request', $appHandler->getRecords()[0]->message);
        $this->assertCount(1, $fallbackHandler->getRecords());
        $this->assertSame('generic', $fallbackHandler->getRecords()[0]->message);
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::getHandlerForChannel
     */
    public function testSegmentPrefixMatching()
    {
        $handler = new TestHandler();
        $router = new ChannelRoutingHandler(['a' => $handler]);

        $router->handle($this->getRecord(Level::Warning, 'exact', channel: 'a'));
        $router->handle($this->getRecord(Level::Warning, 'child', channel: 'a.b'));
        $router->handle($this->getRecord(Level::Warning, 'grandchild', channel: 'a.b.c'));
        $router->handle($this->getRecord(Level::Warning, 'dot-child', channel: 'a.bc'));
        $router->handle($this->getRecord(Level::Warning, 'prefix-only', channel: 'ab'));
        $router->handle($this->getRecord(Level::Warning, 'longer', channel: 'application'));

        $this->assertCount(4, $handler->getRecords());
        $messages = array_map(fn ($record) => $record->message, $handler->getRecords());
        $this->assertSame(['exact', 'child', 'grandchild', 'dot-child'], $messages);
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::getHandlerForChannel
     */
    public function testTiesAreResolvedByDeclarationOrder()
    {
        $first = new TestHandler();
        $second = new TestHandler();
        $router = new ChannelRoutingHandler([['app', $first], ['app', $second]]);

        $router->handle($this->getRecord(channel: 'app'));

        $this->assertCount(1, $first->getRecords());
        $this->assertCount(0, $second->getRecords());
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::handle
     */
    public function testNoMatchWithoutFallbackBubbles()
    {
        $handler = new TestHandler();
        $router = new ChannelRoutingHandler(['app' => $handler]);

        $this->assertFalse($router->handle($this->getRecord(channel: 'other')));
        $this->assertCount(0, $handler->getRecords());
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::handle
     */
    public function testBubbleFlagIsHonored()
    {
        $bubbling = new ChannelRoutingHandler(['app' => new TestHandler()], bubble: true);
        $notBubbling = new ChannelRoutingHandler(['app' => new TestHandler()], bubble: false);

        $this->assertFalse($bubbling->handle($this->getRecord(channel: 'app')));
        $this->assertTrue($notBubbling->handle($this->getRecord(channel: 'app')));
    }

    /**
     * A failing target must not trigger a write to another target or to the
     * fallback: the exception has to propagate and the fallback must stay empty.
     *
     * @covers Monolog\Handler\ChannelRoutingHandler::handle
     */
    public function testExceptionFromTargetPropagatesAndDoesNotReachFallback()
    {
        $throwing = new CountingHandler();
        $throwing->throwOnHandle = true;
        $fallback = new TestHandler();

        $router = new ChannelRoutingHandler(['app' => $throwing], $fallback);

        try {
            $router->handle($this->getRecord(Level::Warning, channel: 'app'));
            $this->fail('Expected the exception thrown by the target handler to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('CountingHandler was configured to throw while handling a record.', $e->getMessage());
        }

        $this->assertCount(0, $throwing->getRecords(), 'The failing target must not have recorded the record.');
        $this->assertCount(0, $fallback->getRecords(), 'The fallback must not be invoked when a target throws.');
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::close
     */
    public function testCloseCallsSharedTargetOnce()
    {
        $shared = new CountingHandler();
        $other = new CountingHandler();

        $router = new ChannelRoutingHandler([
            'app' => $shared,
            'app.db' => $shared,
            'web' => $other,
        ], $shared);

        $router->close();

        $this->assertSame(1, $shared->closeCount, 'Shared target must be closed exactly once.');
        $this->assertSame(1, $other->closeCount);
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::reset
     */
    public function testResetCallsSharedTargetOnce()
    {
        $shared = new CountingHandler();
        $other = new CountingHandler();

        $router = new ChannelRoutingHandler([
            'app' => $shared,
            'app.db' => $shared,
            'web' => $other,
        ], $shared);

        $router->reset();

        $this->assertSame(1, $shared->resetCount, 'Shared target must be reset exactly once.');
        $this->assertSame(1, $other->resetCount);
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::__construct
     */
    public function testRulesSnapshotCannotBeMutatedExternally()
    {
        $appHandler = new TestHandler();
        $dbHandler = new TestHandler();
        $replacement = new TestHandler();
        $rules = ['app' => $appHandler];

        $router = new ChannelRoutingHandler($rules);

        // Mutate the rules array after construction: the router must keep the
        // snapshot it was built with.
        $rules['app.db'] = $dbHandler;
        $rules['app'] = $replacement;

        $router->handle($this->getRecord(channel: 'app.db'));

        $this->assertCount(0, $dbHandler->getRecords(), 'Later additions to the rules array must not affect routing.');
        $this->assertCount(0, $replacement->getRecords(), 'Later replacements of a rule must not affect routing.');
        $this->assertCount(1, $appHandler->getRecords(), 'The snapshot target must still receive the record.');
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::isHandling
     */
    public function testIsHandling()
    {
        $router = new ChannelRoutingHandler(['app' => new TestHandler(Level::Error)]);

        $this->assertTrue($router->isHandling($this->getRecord(Level::Error, channel: 'app')));
        $this->assertFalse($router->isHandling($this->getRecord(Level::Debug, channel: 'app')));
        $this->assertFalse($router->isHandling($this->getRecord(Level::Error, channel: 'other')));
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::handleBatch
     */
    public function testHandleBatchGroupsRecordsByTarget()
    {
        $app = new TestHandler();
        $db = new TestHandler();
        $router = new ChannelRoutingHandler(['app' => $app, 'app.db' => $db]);

        $router->handleBatch([
            $this->getRecord(channel: 'app'),
            $this->getRecord(channel: 'app.db'),
            $this->getRecord(channel: 'app.web'),
            $this->getRecord(channel: 'other'),
        ]);

        $this->assertCount(2, $app->getRecords());
        $this->assertCount(1, $db->getRecords());
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::__construct
     */
    public function testConstructorRejectsInvalidRules()
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChannelRoutingHandler(['app' => new \stdClass()]);
    }

    /**
     * @covers Monolog\Handler\ChannelRoutingHandler::__construct
     */
    public function testConstructorRejectsInvalidPrefixes()
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChannelRoutingHandler(['' => new TestHandler()]);
    }
}
