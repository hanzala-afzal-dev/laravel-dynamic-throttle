# Removing the package

Removing the package stops every rule at once and leaves your data behind. Your app breaks only where its code still names the package, so remove it in two deploys: first what names it, then the package.

To stop limiting for a while, you need to remove nothing: set `DYNAMIC_THROTTLE_ENABLED=false`, or lift every limit with `php artisan dynamic-throttle:bypass on --for=2h` ([during an incident](incidents.md)).

## 1. Remove what names the package

With the package still installed, take out of your app:

- **The middleware:** `dynamic.throttle` in `bootstrap/app.php`, in your route groups and on routes, and the `DynamicThrottleMiddleware` class wherever you named it. Without the package, Laravel takes the name for a class it cannot find, and every request to those routes fails with a 500: `Target class [dynamic.throttle] does not exist.`
- **The facade and the models** in your service providers, such as `DynamicThrottle::scope()` and `DynamicThrottle::strict()`. Without the package, they fail on every request and every Artisan command, `composer remove` included, since it runs `php artisan package:discover`.
- **Your tests'** `DynamicThrottle::fake()`, and their use of the `Rule` and `Exemption` models.
- **Listeners** for the package's [events](events.md). Without it, they never run.

Then deploy. From now on, no rule limits a request, the enforcing ones included. If a rule protected a login or an endpoint that costs money, give that route Laravel's `throttle` in the same deploy.

Wait three minutes before the next step: the scheduler moves the last traffic numbers into the database in that time.

## 2. Remove the package

```bash
composer remove hanzala-afzal/laravel-dynamic-throttle
php artisan optimize:clear
```

`optimize:clear` matters if you cache routes: the cached routes keep the dashboard's, which point at the package's classes. Cache them again afterwards as you deploy, with `php artisan optimize`.

The dashboard's path now answers 404, and the package's Artisan commands and scheduled tasks are gone. It scheduled its tasks itself, so your scheduler names none of them, unless you added them by hand.

## What stays

| What | Where | Effect |
|---|---|---|
| Your rules, exemptions, settings, audit log, hourly traffic numbers and dashboard links | Six tables, `dynamic_throttle_*` | None. They come back into use if you install the package again. |
| The migrations you published | `database/migrations` | None. They use only Laravel's classes, so `migrate`, `migrate:fresh` and `migrate:rollback` still work. |
| The config file you published | `config/dynamic-throttle.php` | None. It uses only Laravel's classes. |
| `DYNAMIC_THROTTLE_*` variables, and the gates `viewDynamicThrottle` and `manageDynamicThrottle` | `.env`, your service provider | None. Nothing reads them. |
| The counters and the traffic numbers | Your cache store, under the `dt:` prefix | None. The counters expire after twice their rule's window, two days at most, and the traffic numbers after two hours. |
| The manifest, the compiled rules | Your cache store, `dt:m:` and a number | None. It never expires: delete it with your cache's tools, or leave it. |

The `dt:` prefix is `DYNAMIC_THROTTLE_PREFIX` if you set it, and Laravel puts your cache's or Redis connection's own prefix before it. Copies of the manifest in APCu go when PHP-FPM restarts.

## Cleaning up

Leave the tables if you may come back, since they hold your rules. To drop them, add a migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Where the package's own migrations created the tables.
    public function getConnection(): ?string
    {
        $connection = config('dynamic-throttle.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        // The exemptions first: they reference the rules.
        foreach (['dynamic_throttle_exemptions', 'dynamic_throttle_rules', 'dynamic_throttle_settings', 'dynamic_throttle_audit_log', 'dynamic_throttle_metrics_hourly', 'dynamic_throttle_links'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
```

Keep the package's migrations next to it, so every environment, a new one included, ends with the same schema. If `DYNAMIC_THROTTLE_DB_CONNECTION` puts the tables on another connection, delete `config/dynamic-throttle.php` only after this migration has run everywhere: the migration reads the connection from it. Then delete the file and the `DYNAMIC_THROTTLE_*` variables.

## Installing it again

Install the package, attach the middleware, and run `php artisan dynamic-throttle:compile`. Your rules, exemptions and settings are still in the database, so everything applies as before, including bypass or a scale factor that was on without an end time: check with `php artisan dynamic-throttle:status`. Counters start from zero.
