# Rules

A rule says which requests to count, who to count them by, and how many to allow. Save rules in the dashboard, with Artisan, or with the `Rule` model. Saving a rule compiles it, and every server applies it within `manifest.refresh_seconds` (2 by default).

## Attributes

| Attribute | Value | Default |
|---|---|---|
| `name` | Up to 100 characters | required |
| `pattern` | A path, with `*` as a wildcard: `login`, `api/*`, `api/*/export` | required |
| `method` | `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, … or `*` | `*` |
| `scope` | Who is counted: `ip`, `user`, `api_token`, `header`, `global` or `custom` | required |
| `scope_parameter` | The header's name for `header`, the resolver's name for `custom` | `null` |
| `max_attempts`, `window_seconds` | The limit: so many requests per so many seconds | required |
| `priority` | Lower runs first | `100` |
| `mode` | `enforce`, or `dry_run` to count without blocking | `enforce` |
| `on_failure` | When storage fails: `open` lets the request through, `closed` answers 503 | `open` |
| `on_missing_identity` | When the scope finds no one, such as a guest under a `user` rule: `ip` counts by IP, `skip` ignores the rule | `ip` |
| `response_override` | A JSON body for the 429, sent as JSON to every client, browsers included | `null` |
| `enabled` | `false` switches the rule off | `true` |

When several rules match a request, each counts it, and the first enforcing rule over its limit rejects it. The request gets Laravel's own 429, rendered by your exception handler:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 42
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1791403200
```

### How a limit counts

A limit counts in a sliding window. The rule keeps a count for the current window, such as this minute, and one for the previous window, and adds the previous count in proportion to how much of it falls within the last `window_seconds`: 30 seconds into a minute, half of the previous minute's requests still count. A request goes through while that sum stays under `max_attempts`. A rejected request is not counted, as with Laravel's `throttle`.

So a client that keeps sending at its limit can get a request through between 429s: as the previous window's requests count for less and less, the sum drops below the limit for a moment.

The count belongs to the rule, whatever its mode: a rule in dry-run counts as it would when enforcing, so a rule you promote starts with what it counted in dry-run. Changing `max_attempts` keeps the count, so a lower limit applies at once. Changing `window_seconds` starts a new count.

## Examples

```php
use Afzal\DynamicThrottle\Models\Rule;

// Five login attempts a minute per IP, with a custom message.
Rule::create([
    'name' => 'Login',
    'method' => 'POST',
    'pattern' => 'login',
    'scope' => 'ip',
    'max_attempts' => 5,
    'window_seconds' => 60,
    'response_override' => ['message' => 'Too many login attempts. Try again in a minute.'],
]);

// A partner's quota, by the key it sends.
Rule::create([
    'name' => 'Partner API',
    'pattern' => 'api/partners/*',
    'scope' => 'header',
    'scope_parameter' => 'X-Partner-Key',
    'max_attempts' => 10000,
    'window_seconds' => 3600,
]);

// One shared limit for an expensive endpoint.
Rule::create([
    'name' => 'Search',
    'pattern' => 'api/search',
    'scope' => 'global',
    'max_attempts' => 500,
    'window_seconds' => 60,
]);
```

## Your own scope

Register a resolver in a service provider's `boot()`, then name it in a `custom` rule:

```php
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Illuminate\Http\Request;

DynamicThrottle::scope('tenant', fn (Request $request) => $request->user()?->tenant_id);
```

```php
Rule::create([
    'name' => 'Per tenant',
    'pattern' => 'api/*',
    'scope' => 'custom',
    'scope_parameter' => 'tenant',
    'max_attempts' => 1000,
    'window_seconds' => 60,
]);
```

A resolver that returns `null` finds no one, and the rule's `on_missing_identity` decides. A class that implements `ScopeResolver` works too.

## An endpoint that costs money

Some endpoints cost money on every call, such as one that calls an AI model. A loop in a client, or one user, can run up a large bill within an hour, and a limit in your code takes a deploy to change. A rule's limit changes in the dashboard, and servers apply it within `manifest.refresh_seconds`.

**1. A limit per user, tried in dry-run first.** Run it for a day, read its would-blocks on the rule's page in the dashboard, or from `RateLimitWouldBlock` ([dry-run rules](dry-run.md)), then promote it: **Promote** on its page, or `$rule->update(['mode' => 'enforce'])`.

```php
Rule::create([
    'name' => 'AI per user',
    'method' => 'POST',
    'pattern' => 'api/ai/*',
    'scope' => 'user',
    'max_attempts' => 20,
    'window_seconds' => 3600,
    'mode' => 'dry_run',
    'response_override' => ['message' => "You have used this hour's AI requests."],
]);
```

If guests can reach the route, the default `on_missing_identity`, `ip`, counts each of them by IP. The `response_override` is always sent as JSON, which suits an API. On a route that serves web pages, leave it out: your exception handler then renders the 429 as it renders `abort(429)`, a page for a browser and JSON for an API client.

**2. A limit per plan,** in place of the per-user rule. Give each plan a rule with a custom scope that finds only that plan's users. The resolver returns `null` for the others, and `on_missing_identity` `skip` makes the rule ignore them ([ADR-0022](../adr/0022-skip-rules-without-identity.md)):

```php
// In a service provider's boot()
foreach (['free', 'pro'] as $plan) {
    DynamicThrottle::scope("{$plan}-user", fn (Request $request) => $request->user()?->plan === $plan ? $request->user()->getAuthIdentifier() : null);
}
```

```php
foreach (['free' => 20, 'pro' => 200] as $plan => $perHour) {
    Rule::create([
        'name' => "AI, {$plan} plan",
        'method' => 'POST',
        'pattern' => 'api/ai/*',
        'scope' => 'custom',
        'scope_parameter' => "{$plan}-user",
        'on_missing_identity' => 'skip',
        'max_attempts' => $perHour,
        'window_seconds' => 3600,
    ]);
}
```

**3. A ceiling for everyone.** A `global` rule on the same route caps the total, whoever calls, such as 2,000 an hour: a budget that holds even when many users stay under their own limits.

**4. During an incident.** Lower the rule's limit on its page in the dashboard. No Artisan command edits a limit: `php artisan dynamic-throttle:disable-rule` switches a rule off, which lifts its limit, and `dynamic-throttle:scale 0.5` halves every rule's limit at once ([during an incident](incidents.md)).

No switch stops a route outright: disabling a rule lifts its limit, `bypass` lifts every limit, and `scale` goes down to a tenth of each limit. The nearest is a `global` rule on the route that allows 1 request a day (`max_attempts` 1, `window_seconds` 86400).

## Exemptions

An exemption lets a client skip every rule, or one rule, until an end date or for good:

```php
use Afzal\DynamicThrottle\Models\Exemption;

// Your office network, from every rule.
Exemption::forIp('203.0.113.0/24')->because('Office network')->save();

// One user, from one rule, for a day.
Exemption::forUser($user)->forRule($rule)->until(now()->addDay())->because('Data migration')->save();

// A tenant of your custom scope.
Exemption::forCustom('tenant', 'acme')->because('Enterprise plan')->save();
```
