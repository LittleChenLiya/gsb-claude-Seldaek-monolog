<?php declare(strict_types=1);

/*
 * Short routing demo for Monolog\Handler\ChannelRoutingHandler.
 *
 * Run with: php demo/channel-routing-demo.php
 *
 * Uses only TestHandler instances (in-memory) - no external service,
 * socket or file is touched. Requires only production dependencies
 * (monolog + psr/log), see tests/native/autoload.php for resolution.
 */

use Monolog\Handler\ChannelRoutingHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

require __DIR__.'/../tests/native/autoload.php';

// Local targets, one per destination "channel family".
$db = new TestHandler();        // handles app.db and everything below, e.g. app.db.queries
$app = new TestHandler();       // handles app and any other app.* sub-channel
$fallback = new TestHandler();  // explicit fallback for everything else

$routing = new ChannelRoutingHandler([
    'app' => $app,
    'app.db' => $db, // most specific rule wins over "app"
], $fallback);

// Several loggers (channels) share the single routing handler.
foreach (['app.db', 'app.web', 'application'] as $channel) {
    $logger = new Logger($channel);
    $logger->pushHandler($routing);
    $logger->warning("hello from {$channel}");
}

$report = static function (string $name, TestHandler $handler): void {
    $channels = array_map(static fn ($record) => $record->channel, $handler->getRecords());
    echo str_pad($name, 10).' received: '.(count($channels) ? implode(', ', $channels) : '(nothing)')."\n";
};

$report('app.db', $db);
$report('app', $app);
$report('fallback', $fallback);
