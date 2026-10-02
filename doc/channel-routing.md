Channel Routing Handler
=======================

`Monolog\Handler\ChannelRoutingHandler` lets one logger stack send records of
different channels to different local handlers. It is a standalone wrapper:
the target handlers it routes to are used as-is and need no modification.

Basic example
-------------

```php
<?php

use Monolog\Handler\ChannelRoutingHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$routing = new ChannelRoutingHandler(
    [
        'app'    => new StreamHandler(__DIR__.'/app.log'),    // app and app.* except app.db*
        'app.db' => new StreamHandler(__DIR__.'/app-db.log'), // most specific rule wins
    ],
    new StreamHandler(__DIR__.'/other.log'), // optional explicit fallback
);

// several loggers (channels) share the single routing handler
foreach (['app.db', 'app.web', 'application'] as $channel) {
    $loggers[$channel] = new Logger($channel);
    $loggers[$channel]->pushHandler($routing);
}

$loggers['app.db']->warning('db');       // -> app-db.log   (most specific rule)
$loggers['app.web']->warning('web');     // -> app.log      (app rule)
$loggers['application']->warning('app'); // -> other.log    (fallback: "app" does NOT match "application")
```

A runnable, dependency-light variant of this demo (in-memory `TestHandler`
targets, no external services) lives in `demo/channel-routing-demo.php`.

Routing semantics
-----------------

- **Full-segment prefix matching.** A rule prefix matches a channel when the
  channel equals the prefix or starts with `prefix.`. The rule `a` therefore
  matches `a` and `a.b` but not `ab`.
- **Most specific match wins.** When several rules match, the longest
  matching prefix is used (`app.db` beats `app` for the channel `app.db`).
- **Declaration order breaks ties.** Rules of equal specificity are resolved
  in the order they were declared. Rules may be given either as a
  `prefix => handler` map or as a list of `[prefix, handler]` pairs; the pair
  form allows declaring the same prefix more than once (first one wins).
- **Explicit fallback.** If no rule matches, the record goes to the fallback
  handler when one was configured. Without a fallback the record is simply
  not handled by this handler and keeps bubbling to the next handler of the
  logger stack.
- **Bubble semantics are preserved.** `handle()` returns `false === $bubble`
  for routed records, exactly like the other wrapping handlers, so
  `bubble: false` stops propagation after routing and `bubble: true` lets
  subsequent handlers of the stack also see the record.
- **No silent re-dispatch on errors.** If the routed handler throws, the
  exception propagates to the caller. The record is *not* re-sent to the
  fallback or any other rule, so a failing target can never cause duplicate
  writes elsewhere. (If you want failure-tolerant fan-out instead, combine
  this handler with `WhatFailureGroupHandler`/`FallbackGroupHandler` as the
  route targets.)
- **Immutable rule snapshot.** The rules are copied and sorted at
  construction time; mutating the array you passed in afterwards has no
  effect on routing.
- **Shared targets are closed/reset once.** When several rules (or the
  fallback) point to the same handler instance, `close()` and `reset()` are
  forwarded to that instance exactly once.

Only production dependencies (`monolog/monolog` itself and `psr/log`) are
required. A PHPUnit-free test exercising the semantics above can be run with
`php tests/native/channel_routing_handler_native_test.php`.
