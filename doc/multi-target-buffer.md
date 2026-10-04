# MultiTargetBufferHandler: independent batches and discard policy

`Monolog\Handler\MultiTargetBufferHandler` sits between `GroupHandler`
(immediate fan-out, no buffering, one failure aborts the rest) and
`BufferHandler` (buffers for a single nested handler). It keeps **one
independent buffer per named target** and commits each target separately
when `flush()` (or `close()`/`reset()`) is called.

## Independent batch policy

- Every `handle()` clones the record into each target's own buffer, so
  targets never share record instances and the caller cannot mutate a
  record that is already buffered.
- `flush()` sends each target's buffer as one `handleBatch()` call and
  clears that target's buffer **only on success**. A target that already
  succeeded is never sent the same records again, even if another target
  keeps failing.
- When a target throws, the handler catches the error, stores it
  (`getError($target)`) and keeps the exact failed batch as a *pending
  batch* (`getPendingBatch($target)`). The nested handler only ever
  receives clones, so a failing handler cannot corrupt the batch that
  will be retried.
- On the next `flush()`, only targets with a pending batch retry, and
  they retry **exactly** the pending batch. Records that arrived while
  the target was failing wait in that target's buffer and are sent as a
  separate, later batch once the retry has succeeded — per-target
  ordering is preserved and the pending batch is never modified.
- `flush()` never throws: one target's failure does not prevent the
  other targets from being committed.

## Discard policy

Each target buffer can be capped with the `$bufferLimit` constructor
argument (0 = unlimited, the default). When a target's buffer is full,
the **oldest** record of that target is dropped to make room for the new
one. Drops are never silent:

- a per-target counter is incremented (`getDiscardedCount($target)`), and
- the optional overflow callback is invoked as
  `function (int|string $target, LogRecord $dropped, int $discardedCount): void`
  where `$discardedCount` is the running total for that target.

The cap applies to the accumulating buffer; a pending failed batch is
always retried in full and is not subject to further discards.

## Example

```php
use Monolog\Handler\MultiTargetBufferHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$handler = new MultiTargetBufferHandler(
    handlers: [
        'file' => new StreamHandler(__DIR__.'/app.log'),
        'remote' => new MyRemoteBatchHandler(), // any HandlerInterface
    ],
    bufferLimit: 1000, // per target; oldest records are dropped beyond that
    overflowCallback: function (int|string $target, LogRecord $dropped, int $count) {
        error_log("Dropped #{$count} record for {$target}: {$dropped->message}");
    },
);

$log = new Logger('app');
$log->pushHandler($handler);

$log->warning('something happened');

$handler->flush(); // commits 'file' and 'remote' independently

if (null !== $error = $handler->getError('remote')) {
    // 'remote' failed: its batch is kept and will be retried by the next
    // flush() with identical content, while 'file' is left alone.
}
```
