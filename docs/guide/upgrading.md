# Upgrading

How to move your app to a new version of the package: what a version number promises, the steps for every upgrade, and what each release needs.

## What a version promises

The package follows semantic versioning. Before 1.0:

- **A patch version,** such as 0.2.1, never needs a change in your app. `composer update` brings it.
- **A minor version,** such as 0.3.0, may change how something works. `composer update` does not bring it: the `^0.2` that Composer saved in your `composer.json` stops before 0.3. The release's first paragraph in the [changelog](../../CHANGELOG.md) says what to change in your app, if anything.

## The steps

1. **Read the release notes** in the [changelog](../../CHANGELOG.md), for each version you skip. A release that needs a step says so in its first paragraph, and the sections below repeat it.
2. **Update the package:**

   ```bash
   composer update hanzala-afzal/laravel-dynamic-throttle                # a patch version
   composer require hanzala-afzal/laravel-dynamic-throttle:^0.3          # a new minor version
   ```

3. **Add the new tables,** if the release brings one: see [new tables](#new-tables).
4. **Deploy as usual,** and run `php artisan dynamic-throttle:compile` once the new code is in place, as the install steps do. Servers then never wait for the rules to be rebuilt, even after a version that stores them in a new way.
5. **Check your setup** with `php artisan dynamic-throttle:health`. A new version can add a check, so a deploy script that runs it with `--strict` may stop on a new warning: the release notes name each new check.

Your config file needs no change. A setting that a new version adds takes its default, even in a `config/dynamic-throttle.php` you published before.

## New tables

Some releases add a table, such as the one for dashboard links. Publish the migrations and run them:

```bash
php artisan vendor:publish --tag=dynamic-throttle-migrations
php artisan migrate
```

**Before `migrate`, delete the copies you already have.** Publishing copies every migration of the package again, under new dates, also those your app already ran. `migrate` would try to create their tables again, and stop. Your app already has a migration when a file in `database/migrations` ends with the same name after its date, such as `create_dynamic_throttle_rules_table.php`. Keep only the new copies of the others.

## During a rolling deploy

Servers of the old and the new version can run side by side while you deploy, and each keeps applying the rules. Save rule changes once every server runs the new version: a version that stores the rules in a new way reads only the rules it compiled itself.

## From 0.1 to 0.2

- **Require the new version:** `composer require hanzala-afzal/laravel-dynamic-throttle:^0.2`.
- **Nothing else changes** for an app that leaves [links](dashboard.md#links) off, which is the default.
- **To turn links on,** add their table: publish the migrations, delete the new copies of the five your app already has, keep `create_dynamic_throttle_links_table.php`, and run `php artisan migrate`. Then set `DYNAMIC_THROTTLE_DASHBOARD_LINKS=true`.

## From a beta to 0.1

- **Update:** `composer update hanzala-afzal/laravel-dynamic-throttle` brings 0.1, since the `^0.1@beta` that Composer saved allows it.
- **Rules for `web` routes,** such as those from the Login, Password reset or Sign-up templates, apply only once `dynamic.throttle` runs on the `web` group too ([attaching the middleware](middleware.md)).
- **A new health check,** `unreached`, warns about a rule that matches only routes without the middleware, so `dynamic-throttle:health --strict` may stop on it ([health checks](health.md)).
