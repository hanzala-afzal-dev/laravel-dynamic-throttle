# Events

Besides `RateLimitWouldBlock` ([dry-run rules](dry-run.md)) and `StorageUnavailable` ([when something fails](failures.md)), two events tell your integrations what happens. Both carry plain values, so queued listeners work, and a listener that throws is reported, never thrown.

```php
use Afzal\DynamicThrottle\Events\RateLimitExceeded;
use Afzal\DynamicThrottle\Events\RuleChanged;

Event::listen(function (RateLimitExceeded $event) {
    Log::notice("{$event->ruleName} blocked {$event->method} {$event->path} for {$event->retryAfter} s");
});

Event::listen(function (RuleChanged $event) {
    Log::info("Rule {$event->ruleId} ({$event->ruleName}) {$event->change->value}", ['before' => $event->before, 'after' => $event->after]);
});
```

- **`RateLimitExceeded`** is dispatched after the response, once for each request an enforcing rule answers 429, with the same values as `RateLimitWouldBlock`, so one listener can handle both. A request's events go out in the order the rules met them: the would-blocks, then the 429, then `StorageUnavailable`.
- **`RuleChanged`** is dispatched when a rule is created, updated or deleted through the model, after the transaction commits, and never for a change that rolls back. Disabling and enabling a rule are updates of `enabled`. `change` is `created`, `updated` or `deleted`, and `before` and `after` hold the rule's columns, without its timestamps, with enums as their values: null before a creation and after a deletion, and only the changed columns for an update. A rule loaded with some of its columns only gives those, and `''` for a name it was loaded without. In nested transactions, the events go out when the outermost one commits, the innermost's first, as Laravel runs after-commit callbacks.
