# Contract Processors

Contract processors are an opt-in, stricter alternative to the default processor
stack. Every processor is registered together with an explicit
`Monolog\Processor\ProcessorContract` declaring the dot-notated `LogRecord`
paths it is allowed to modify (e.g. `extra`, `extra.request_id`, `message`).

Each processor runs on an **independent candidate record**: arrays are
deep-copied (array references included) and known mutable values are cloned, so
the original record can never be altered through the candidate. After each
callback the result is deep-verified against the contract:

- scalars and arrays are verified recursively;
- `DateTimeImmutable`, `DateTime` and enums are verified by value;
- custom objects need a registered verifier (a callback returning a scalar/array
  snapshot of the object). Unknown mutable objects without a verifier are
  flagged non-verifiable — they are never inspected through getters — and the
  chain aborts *before* the next callback runs;
- if a processor changes anything outside its declared paths, the whole chain is
  rejected: the original record stays untouched and the handler does not write,
  so no half-processed log line is ever emitted.

Processors run in the same order and with the same callback counts as the
default stack: the last pushed processor runs first, each callback runs exactly
once per record unless the chain aborts earlier. The default processor mechanism
(`ProcessableHandlerTrait`) is unchanged; handlers opt in by extending
`Monolog\Handler\AbstractContractProcessingHandler`.

## Running the tests

```bash
vendor/bin/phpunit tests/Monolog/Processor/ContractProcessorChainTest.php tests/Monolog/Handler/AbstractContractProcessingHandlerTest.php
# or, as part of the whole suite:
vendor/bin/phpunit --filter 'ContractProcessorChain|AbstractContractProcessingHandler'
```

## Example 1: a processor that may only add a request id to `extra`

```php
<?php

use Monolog\Handler\AbstractContractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorContract;

final class RequestIdProcessor
{
    public function __construct(private string $requestId)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['request_id'] = $this->requestId;

        return $record;
    }
}

$handler = new class(Level::Debug) extends AbstractContractProcessingHandler {
    protected function write(LogRecord $record): void
    {
        echo $record->formatted;
    }
};

// the contract pins this processor to the extra.request_id path only
$handler->pushContractProcessor(
    new RequestIdProcessor('req-123'),
    ProcessorContract::allowing('extra.request_id'),
);
```

## Example 2: a processor that may only rewrite the message

```php
<?php

use Monolog\LogRecord;
use Monolog\Processor\ProcessorContract;

final class MessagePrefixProcessor
{
    public function __construct(private string $prefix)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(message: $this->prefix.$record->message);
    }
}

$handler->pushContractProcessor(
    new MessagePrefixProcessor('[worker-7] '),
    ProcessorContract::allowing('message'),
);
```

If `MessagePrefixProcessor` were to also change `context` (or anything else not
listed in its contract), the chain would reject the record, `handle()` would
return `false` without calling `write()`, and the violation would be available
via `$handler->getLastViolation()`.

## Verifying custom value objects

Records carrying custom objects can be processed once a verifier is registered
for their class. The verifier must return a snapshot built from scalars and/or
arrays only:

```php
$handler->registerValueVerifier(
    UserId::class,
    fn (UserId $id): array => ['value' => $id->value],
);
```

Without a verifier, a record containing an unknown mutable object is considered
non-verifiable: the processor callbacks are not invoked at all and nothing is
written.
