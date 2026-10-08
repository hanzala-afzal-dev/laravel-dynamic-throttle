# Dry-run rules

A rule whose `mode` is `dry_run` counts requests exactly as an enforcing rule does, but never rejects one and sets no headers. When it would have rejected a request, the middleware dispatches `RateLimitWouldBlock` after the response has been sent, so a listener never slows the response ([ADR-0012](../adr/0012-dry-run.md)):

```php
use Afzal\DynamicThrottle\Events\RateLimitWouldBlock;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function (RateLimitWouldBlock $event) {
    Log::info("{$event->ruleName} would have blocked {$event->method} {$event->path}", [
        'rule' => $event->ruleId,
        'client' => $event->identityHash,
        'retry_after' => $event->retryAfter,
    ]);
});
```

- **When.** It fires on exactly the requests an enforcing copy of the rule would reject: one event for each dry-run rule that would block, in the order the rules run.
- **What.** Plain values, so a queued listener works too: the rule's `ruleId`, `ruleName`, `limit`, `windowSeconds` and `scope`, the client's `identityHash`, the `retryAfter` it would have got, and the request's `method` and `path`. The client appears only as the hash its counter uses. A listener that needs the IP or the user reads them from the request.
- **Failures.** A listener that throws is reported to your exception handler, and the other events are still dispatched.
- **Tests.** `Event::fake()` records it like any other event.
