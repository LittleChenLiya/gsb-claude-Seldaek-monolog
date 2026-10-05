<?php declare(strict_types=1);

/*
 * Demo for Monolog\Handler\EventTimeOrderHandler (explicit event-time
 * ordering with a watermark of "max accepted event time - allowed lateness").
 *
 * Run: php examples/event_time_order_demo.php
 */

require __DIR__.'/../vendor/autoload.php';

use Monolog\Handler\AbstractHandler;
use Monolog\Handler\EventTimeOrderHandler;
use Monolog\Level;
use Monolog\LogRecord;

// Downstream handler: prints every delivered record; messages containing
// "fail" simulate a downstream outage.
$downstream = new class extends AbstractHandler {
    public function handle(LogRecord $record): bool
    {
        if (str_contains($record->message, 'fail')) {
            throw new \RuntimeException('simulated downstream outage');
        }
        printf("[delivered] event=%s message=%s\n", $record->datetime->format('U'), $record->message);

        return true;
    }
};

$handler = new EventTimeOrderHandler(
    $downstream,
    allowedLateness: 2.0,
    lateRecordCallback: static function (LogRecord $record): void {
        printf("[late]      event=%s message=%s -> diverted to late callback\n", $record->datetime->format('U'), $record->message);
    },
    unknownHandoffCallback: static function (LogRecord $record, \Throwable $error): void {
        printf("[unknown]   event=%s message=%s -> handoff state UNKNOWN (%s)\n", $record->datetime->format('U'), $record->message, $error->getMessage());
    },
);

$enqueue = static function (int $t, string $message) use ($handler): void {
    printf("-- enqueue   event=%d message=%s (watermark=%s)\n", $t, $message, var_export($handler->getWatermark(), true));
    $handler->handle(new LogRecord(new \DateTimeImmutable('@'.$t), 'demo', Level::Info, $message));
};

// Five log records: 10, 12, 11 arrive in order, 9 is late, 13 fails downstream.
$enqueue(10, 'boot');
$enqueue(12, 'login');
$enqueue(11, 'cache-warm');
$enqueue(9, 'late-metrics');
$enqueue(13, 'flush-fail');

echo "-- close\n";
$handler->close();

echo "-- final report\n";
if ($handler->hasUndeliveredRecords()) {
    foreach ($handler->getUnknownHandoffs() as $entry) {
        printf("[report]    undelivered: event=%s message=%s error=%s\n", $entry['record']->datetime->format('U'), $entry['record']->message, $entry['error']->getMessage());
    }
} else {
    echo "[report]    all records delivered\n";
}
