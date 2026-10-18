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
| `response_override` | A JSON body for the 429 | `null` |
| `enabled` | `false` switches the rule off | `true` |

When several rules match a request, each counts it, and the first enforcing rule over its limit rejects it. The request gets Laravel's own 429, rendered by your exception handler:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 42
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1791403200
```

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
