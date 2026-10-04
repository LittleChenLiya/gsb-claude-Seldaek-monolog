<?php declare(strict_types=1);

/*
 * Native PHP demo for the optional ContextBudgetProcessor.
 *
 * Requirements: PHP 8.1+ and the production dependency psr/log only:
 *
 *     php /path/to/composer.phar install --no-dev --ignore-platform-reqs
 *
 * No dev dependencies (no PHPUnit, no log handlers/transports) are needed.
 *
 * Run with:
 *
 *     php examples/context_budget_demo.php
 */

require __DIR__.'/../vendor/autoload.php';

use Monolog\Level;
use Monolog\LogRecord;
use Monolog\JsonSerializableDateTimeImmutable;
use Monolog\Processor\ContextBudgetProcessor;

/** A plain callable that prints records, so no handler package is required. */
$printHandler = static function (LogRecord $record): void {
    echo str_repeat('-', 60).PHP_EOL;
    echo 'level:    '.$record->level->getName().PHP_EOL;
    echo 'message:  '.$record->message.PHP_EOL;
    echo 'context:  '.json_encode($record->context, JSON_UNESCAPED_UNICODE).PHP_EOL;
    echo 'extra:    '.json_encode($record->extra, JSON_UNESCAPED_UNICODE).PHP_EOL;
};

$dispatch = static function (ContextBudgetProcessor $processor, array $context, string $message) use ($printHandler): void {
    $record = new LogRecord(
        datetime: new JsonSerializableDateTimeImmutable(true),
        channel: 'demo',
        level: Level::Warning,
        message: $message,
        context: $context,
    );

    $printHandler($processor($record));
};

echo 'ContextBudgetProcessor demo (budget = 32 estimated UTF-8 bytes)'.PHP_EOL;

$processor = new ContextBudgetProcessor(32);

// 1. Two small fields followed by one oversized field: the first two are
//    kept in original order; the third is dropped and reported with its
//    dotted path and estimated byte cost.
$dispatch($processor, [
    'user_id' => 42,                                   // 1+7+2 = 10
    'event' => 'login',                                // 1+5+5 = 11
    'payload' => str_repeat('A', 64),                  // 1+7+64 = 72 -> dropped
], 'small fields survive, the big one is dropped');

// 2. Chinese text is charged in UTF-8 bytes (3 bytes per character),
//    never per character. "数据" = 2 characters but 6 bytes.
$dispatch($processor, [
    '数据' => '日志', // 1 + 6 key bytes + 6 value bytes = 13 -> fits
    '超长字段' => str_repeat('中', 30), // 1+12+90 = 103 -> dropped
], 'UTF-8 byte budgeting for multibyte keys and values');

// 3. A nested value that does not fit is rejected as a whole entry. It is
//    never half-truncated.
$dispatch($processor, [
    'tag' => 'ok',                         // 1+3+2 = 6 -> fits
    'nested' => ['deep' => str_repeat('z', 40)], // 1+6+2+1+4+40 = 54 -> dropped whole
], 'nested entries are kept whole or dropped whole');

// 4. Unknown objects are opaque leaves estimated from their class name.
//    No __toString / JsonSerializable code is executed.
$object = new class {
    public function __toString(): string
    {
        throw new \LogicException('user code must never run during budgeting');
    }
};

$dispatch($processor, ['thing' => $object], 'unknown objects do not execute user code');

echo PHP_EOL.'Done.'.PHP_EOL;
