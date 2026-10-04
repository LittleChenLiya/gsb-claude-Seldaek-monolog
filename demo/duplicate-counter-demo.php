<?php declare(strict_types=1);

/*
 * Demo: DuplicateCounterProcessor
 *
 * This script demonstrates the DuplicateCounterProcessor by logging a few
 * messages and printing the records with their duplicate sequence numbers.
 *
 * Run: php demo/duplicate-counter-demo.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Processor\DuplicateCounterProcessor;

// Create a logger with the duplicate counter processor
$logger = new Logger('demo');
$logger->pushProcessor(new DuplicateCounterProcessor(
    capacity: 5,
    contextFields: ['user_id']
));

// Use a simple handler that prints records as arrays
$logger->pushHandler(new class extends \Monolog\Handler\AbstractProcessingHandler {
    protected function write(\Monolog\LogRecord $record): void
    {
        echo sprintf(
            "[%s] %s: %s | context=%s | extra=%s\n",
            $record->level->getName(),
            $record->channel,
            $record->message,
            json_encode($record->context),
            json_encode($record->extra)
        );
    }
});

echo "=== DuplicateCounterProcessor Demo ===\n\n";

// 1. Same identity consecutive records -> sequence 1, 2
echo "--- Same identity consecutive records ---\n";
$logger->info('User logged in', ['user_id' => 100]);
$logger->info('User logged in', ['user_id' => 100]);
$logger->info('User logged in', ['user_id' => 100]);

// 2. Context changes from int 1 to string 1 -> restarts from 1
echo "\n--- Context int 1 vs string 1 (different identities) ---\n";
$logger->info('User logged in', ['user_id' => 1]);
$logger->info('User logged in', ['user_id' => '1']);

// 3. Different level -> different identity
echo "\n--- Different level (different identity) ---\n";
$logger->info('User logged in', ['user_id' => 100]);
$logger->warning('User logged in', ['user_id' => 100]);

// 4. Different message -> different identity
echo "\n--- Different message (different identity) ---\n";
$logger->info('User logged in', ['user_id' => 200]);
$logger->info('User logged out', ['user_id' => 200]);

// 5. Capacity eviction (capacity=2): third identity evicts LRU entry
echo "\n--- Capacity eviction (capacity=2) ---\n";
$evictLogger = new Logger('demo');
$evictLogger->pushProcessor(new DuplicateCounterProcessor(2, ['user_id']));
$evictLogger->pushHandler(new class extends \Monolog\Handler\AbstractProcessingHandler {
    protected function write(\Monolog\LogRecord $record): void
    {
        echo sprintf(
            "[%s] %s: %s | context=%s | extra=%s\n",
            $record->level->getName(),
            $record->channel,
            $record->message,
            json_encode($record->context),
            json_encode($record->extra)
        );
    }
});

$evictLogger->info('Message A', ['user_id' => 1]); // A=1
$evictLogger->info('Message B', ['user_id' => 2]); // B=1
$evictLogger->info('Message A', ['user_id' => 1]); // A=2 (moved to MRU)
$evictLogger->info('Message C', ['user_id' => 3]); // C=1 (B evicted)
$evictLogger->info('Message B', ['user_id' => 2]); // B=1 (was evicted, fresh start)

// 6. Reset clears cache
echo "\n--- Reset clears cache ---\n";
$resetLogger = new Logger('demo');
$resetLogger->pushProcessor($resetProcessor = new DuplicateCounterProcessor(5, ['user_id']));
$resetLogger->pushHandler(new class extends \Monolog\Handler\AbstractProcessingHandler {
    protected function write(\Monolog\LogRecord $record): void
    {
        echo sprintf(
            "[%s] %s: %s | context=%s | extra=%s\n",
            $record->level->getName(),
            $record->channel,
            $record->message,
            json_encode($record->context),
            json_encode($record->extra)
        );
    }
});

$resetLogger->info('Test message', ['user_id' => 1]); // count=1
$resetLogger->info('Test message', ['user_id' => 1]); // count=2
$resetProcessor->reset();
$resetLogger->info('Test message', ['user_id' => 1]); // count=1 (after reset)

echo "\n=== Demo complete ===\n";
