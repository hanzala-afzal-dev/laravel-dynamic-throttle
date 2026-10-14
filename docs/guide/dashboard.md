# The dashboard

The dashboard ships with the package, as Horizon's does, and its pages arrive with the first release. This page covers what is in place already: where it is served, who gets in, and how it protects itself ([ADR-0015](../adr/0015-dashboard-security-and-audit.md), [SPEC-018](../specs/018-dashboard-access-and-guardrails.md)).

## Where it is

At `/dynamic-throttle`, behind your `web` middleware group:

```php
// config/dynamic-throttle.php
'dashboard' => [
    'enabled' => env('DYNAMIC_THROTTLE_DASHBOARD', true),
    'path' => env('DYNAMIC_THROTTLE_DASHBOARD_PATH', 'dynamic-throttle'),
    'domain' => env('DYNAMIC_THROTTLE_DASHBOARD_DOMAIN'),
    'middleware' => ['web'],
    'rate_limit' => 120,
],
```

An empty path serves no dashboard, since it would take your site's root, and `php artisan dynamic-throttle:health` warns about it.

## Who gets in

Two gates decide: `viewDynamicThrottle` to look, `manageDynamicThrottle` to change rules, exemptions and the emergency settings. Define them in a service provider's `boot()`:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('viewDynamicThrottle', fn (User $user) => $user->is_support || $user->is_admin);
Gate::define('manageDynamicThrottle', fn (User $user) => $user->is_admin);
```

- **Until you define a gate, only the `local` environment gets in**, guests too, as with Horizon. In production, nobody does.
- **Managing includes viewing,** so defining `manageDynamicThrottle` alone is enough for a team of admins.
- **Guests never get in** through a gate you define, unless its callback accepts a null user (`?User $user`).
- **A denied request gets a 403,** never a redirect to a login page your app may not have. To send guests to your login page first, add `auth`: `'middleware' => ['web', 'auth']`.

## How it protects itself

- **No rule ever applies to the dashboard,** even a `*` rule, and even with `dynamic.throttle` as global middleware: the package skips its routes by name, and its path too. So a bad rule can never lock you out of the place where you would fix it.
- **Laravel's own `throttle` limits it** to `rate_limit` requests a minute per user, or per IP for a guest, 120 by default; 0 turns the limit off.
- **Its responses are never cached,** and their type is never guessed: `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`.

## What it refuses

Everything the dashboard saves is checked first, so a rule saved there always compiles:

- **Rules:** a name of up to 100 characters, a pattern of up to 255 without spaces, 1 to 4,294,967,295 attempts, a window of 1 second to 1 day, a header scope with a valid header name, a custom scope your app registered, and a response override of at most 4 KB. **At most 500 rules can be enabled.**
- **Exemptions:** an IP address or a CIDR range, a user as `App\Models\User:42` or by id alone as your default guard's model, a custom scope your app registered, a rule that exists, and an expiry in the future.
- **Emergency settings:** the same durations and factors as the CLI.

**Dangerous changes need confirmation,** in every environment: disabling or deleting an enforcing rule, turning bypass on, and a factor below 1. The dashboard asks you in a dialog. Its JSON API answers 409 without `"confirm": true`, with a message that says what the change would do, such as "Bypass lifts every limit until 2027-01-15 15:30 UTC."

## When it is unreachable

The CLI can do everything an incident needs: list, disable and enable rules, bypass and scale ([during an incident](incidents.md), [runbook](../runbook.md)).
