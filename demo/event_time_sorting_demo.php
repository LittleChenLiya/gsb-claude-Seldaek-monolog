<?php declare(strict_types=1);

/*
 * Five log demos for the EventTimeSortingHandler.
 *
 * Run:
 *   php demo/event_time_sorting_demo.php
 *
 * The handler buffers records and delivers them sorted by event time (UTC
 * instant) and enqueue order. A watermark is derived from the maximum accepted
 * event time minus an allowed lateness. Records past the watermark go to an
 * independent late callback. On close, remaining records are flushed in order.
 */

require __DIR__.'/../vendor/autoload.php';

use Monolog\Handler\EventTimeSortingHandler;
use Monolog\Handler\StreamHandler;
use Monolog\JsonSerializableDateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger;

// A simple late callback that prints the rejected record.
$lateCallback = function (LogRecord $record): void {
    fwrite(STDERR, sprintf(
        "[late] event=%s msg=%s\n",
        $record->datetime->format('H:i:s'),
        $record->message
    ));
};

// An unfinished callback that reports records whose handoff status is unknown.
$unfinishedCallback = function (array $records, ?\Throwable $e): void {
    if (\count($records) > 0) {
        fwrite(STDERR, sprintf(
            "[unfinished] %d record(s) not confirmed delivered: %s\n",
            \count($records),
            implode(', ', array_map(fn(LogRecord $r): string => $r->message, $records))
        ));
    }
};

// Wrap a StreamHandler that writes to stdout.
$stream = new StreamHandler('php://stdout', Level::Debug);
$handler = new EventTimeSortingHandler(
    $stream,
    allowedLateness: 2,        // watermark = maxEventTime - 2
    bufferLimit: 0,            // unlimited buffer
    lateCallback: $lateCallback,
    unfinishedCallback: $unfinishedCallback,
);

$logger = new Logger('demo');
$logger->pushHandler($handler);

// Helper to build a record with an explicit event time.
$logAt = function (int $timestamp, string $message) use ($logger): void {
    $dt = (new JsonSerializableDateTimeImmutable(true))->setTimestamp($timestamp);
    $logger->addRecord(Level::Info, $message, [], $dt);
};

echo "=== Five log demos (lateness=2) ===" . PHP_EOL;
echo "Watermark = max(eventTime) - 2" . PHP_EOL . PHP_EOL;

// Demo 1: event time 10 -> buffered, watermark=8, not yet due.
echo "[1] log event=10 (alpha)" . PHP_EOL;
$logAt(10, 'alpha');

// Demo 2: event time 12 -> watermark=10, 10 is due and delivered.
echo "[2] log event=12 (beta) -> watermark=10, alpha(10) delivered" . PHP_EOL;
$logAt(12, 'beta');

// Demo 3: event time 11 -> buffered (11 > watermark 10).
echo "[3] log event=11 (gamma) -> buffered" . PHP_EOL;
$logAt(11, 'gamma');

// Demo 4: event time 9 -> past watermark (9 < 10), goes to late callback.
echo "[4] log event=9 (delta) -> late callback" . PHP_EOL;
$logAt(9, 'delta');

// Demo 5: event time 13 -> watermark=11, gamma(11) due and delivered.
echo "[5] log event=13 (epsilon) -> watermark=11, gamma(11) delivered" . PHP_EOL;
$logAt(13, 'epsilon');

echo PHP_EOL . "=== close() -> flush remaining (beta=12, epsilon=13) ===" . PHP_EOL;
$handler->close();

echo PHP_EOL . "=== Done. Sorted output: alpha, gamma, beta, epsilon ===" . PHP_EOL;
echo "=== (delta was rejected as late and never delivered) ===" . PHP_EOL;
