# When something fails

A rule's `on_failure` decides what happens when its counter cannot be checked: the counter store fails, or the rule cannot tell who the client is, such as when your custom scope resolver throws ([ADR-0010](../adr/0010-failure-policy.md)).

- **`open`, the default.** The request goes through. Only the rules that counted it before the failure set rate-limit headers.
- **`closed`.** The request gets a 503 (`ServiceUnavailableHttpException`), which your exception handler renders as it renders `abort(503)`, with a `Retry-After`. Use it for a rule that must hold during an outage, such as one on a login route. A dry-run rule never answers 503. While the cache cannot be read, the rules still apply from the last manifest the process read, or on PHP-FPM with APCu, the last one the server read. Only with none is no rule known, so none answers 503.
- **The cooldown.** After the counter store fails, the package skips it for `failure.cooldown_seconds` (`DYNAMIC_THROTTLE_COOLDOWN`, default 10, and 0 turns it off). An outage then costs one failed call per cooldown instead of one per request: meanwhile, fail-open rules let requests through at once, and fail-closed rules answer 503 at once, with `Retry-After` set to what is left of the cooldown. The cooldown lives in the process, for Octane and queue workers, and in APCu when the extension is enabled, where a server's PHP-FPM workers share it. **On PHP-FPM, enable APCu**: without it, each request tries the store again. A Redis that hangs, instead of refusing, holds each try for the connection's timeout, so give it short ones ([timeouts](storage-and-runtimes.md#timeouts)).
- **Reports.** Every failure is reported to your exception handler, but each cause at most once per cooldown, so an outage or a broken resolver cannot flood your error tracker. A cause is the exception's class and where it was thrown. A config mistake, such as an unknown `driver`, counts as a store that failed.

When the counter store fails, the middleware dispatches `StorageUnavailable` after the response, at most once per request, and so about once per cooldown. It carries plain values, so a queued listener works too: the error's `exceptionClass` and `message`, whether it failed on a `release`, and the `cooldownSeconds`.

```php
use Afzal\DynamicThrottle\Events\StorageUnavailable;

Event::listen(function (StorageUnavailable $event) {
    Log::warning("Rate limiting is degraded: {$event->message}");
});
```

**Strict mode.** Programming mistakes are reported like any other failure. To have them thrown in development instead, turn on strict mode in a service provider's `boot()`:

```php
DynamicThrottle::strict(! app()->isProduction());
```

The middleware then rethrows config mistakes, such as an unknown `driver`, and errors from your own code, such as a custom scope resolver or a guard that throws. Storage errors, the manifest and a client IP that cannot be read still follow `on_failure`, since no code can prevent them.
