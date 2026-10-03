<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Short demo of the ChannelRoutingHandler.
 *
 * A single Logger sends records of different channels to different local
 * handlers, without contacting any external service. Run it with:
 *
 *   php doc/channel-routing-demo.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Monolog\Handler\ChannelRoutingHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;

// One local handler per target. TestHandler keeps the received records in
// memory so the routing can be inspected after the fact.
$appTarget = new TestHandler();
$dbTarget = new TestHandler();
$fallbackTarget = new TestHandler();

// Rules are matched against full channel segments: "app" matches "app" and
// "app.web" but not "application". The longest matching prefix wins.
$router = new ChannelRoutingHandler([
    'app' => $appTarget,
    'app.db' => $dbTarget,
], $fallbackTarget);

$logger = new Logger('app');
$logger->pushHandler($router);

// Log records with different channels through the same logger/router.
$logger->withName('app.db')->log(Level::Warning, 'slow query');
$logger->withName('app.web')->log(Level::Warning, 'request handled');
$logger->withName('application')->log(Level::Warning, 'generic event');

foreach (['db' => $dbTarget, 'app' => $appTarget, 'fallback' => $fallbackTarget] as $name => $target) {
    foreach ($target->getRecords() as $record) {
        echo sprintf("%-9s channel=%-11s message=%s\n", $name, $record->channel, $record->message);
    }
}
