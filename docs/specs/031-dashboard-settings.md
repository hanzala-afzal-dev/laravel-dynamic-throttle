# SPEC-031: The Settings page

- Status: Done
- ADRs: ADR-0010, ADR-0020, ADR-0028
- Roadmap phase: 5

## Problem

The dashboard links to a Settings page that is a placeholder. When limits do not behave, an admin needs to:
- see every health check, not only the Overview's three worst, with what each looks for and what to do;
- see the configuration the servers run with, as the app resolved it, without shell access;
- know what to run when the dashboard itself is down.

The health report is served already (`GET api/health`, SPEC-017). The effective configuration is not.

## Behavior

### The page, at `{path}/settings`
Three cards, for viewers and managers alike. Nothing on the page changes anything (decision 1).

- **Health,** from `api/health`:
  - the overall status, "Healthy", "Warnings" or "Errors", and "Check again";
  - every check, errors first, then warnings, then the ok ones, then the skipped ones, each with its status, its id, what it looks for, and its message, which says what to do.
- **Configuration,** from a new `GET api/config`:
  - the driver the counters use, such as "redis, connection default", and the cache store of the manifest;
  - the package's config, `config('dynamic-throttle')`, as the app resolved it from its files and environment, grouped by its top-level keys;
  - the custom scopes the app registered, and whether strict mode is on;
  - a config the driver cannot be built from shows the error in place of the driver, and the rest still shows.
- **When the dashboard is down:** the Artisan commands that do what the dashboard does, to copy:
  - `php artisan dynamic-throttle:bypass on --for=1h` and `off`;
  - `php artisan dynamic-throttle:scale 0.5 --for=1h` and `off`;
  - `php artisan dynamic-throttle:list`, then `dynamic-throttle:disable-rule {id}` or `enable-rule {id}`;
  - `php artisan dynamic-throttle:status` and `dynamic-throttle:health`;
  - a link to the runbook's scenarios in the guide.

### `GET api/config`
- Either gate, like every read (SPEC-018), named `dynamic-throttle.api.config`.
- Answers:
  ```json
  {
    "driver": "redis, connection default",
    "driver_error": null,
    "manifest_store": "default",
    "config": { "enabled": true, "driver": "auto", "...": "..." },
    "scopes": ["tenant"],
    "strict": false
  }
  ```
- `config` is the package's config as the app holds it. A value JSON cannot hold, such as a closure in an app's own key, shows as its type, such as `"(Closure)"`. A key whose name holds `password`, `secret` or `token` shows as `"(hidden)"`, should an app add one (decision 3).
- It reads no database and no counter store, so it answers while Redis or the database is down.

## Acceptance criteria

### Server (Pest)
- **AC-1** `GET api/config` answers a viewer the driver as `dynamic-throttle:status` describes it, the manifest's cache store, the package's config with the values the app set, the registered custom scopes and strict mode. A guest in production gets 403.
- **AC-2** A config the driver cannot be built from, such as an unknown driver, answers 200 with `driver: null` and the error in `driver_error`. A closure answers as `"(Closure)"`, and a key named `redis_password` as `"(hidden)"`.

### Frontend (Vitest)
- **AC-3** The health card lists every check, errors, then warnings, then ok, then skipped, each with its status, id, what it looks for and its message. "Check again" asks `api/health` again.
- **AC-4** The configuration card shows the driver, the manifest store, each top-level group of the config with its keys and values, the custom scopes and strict mode. A driver error shows in place of the driver.
- **AC-5** The CLI card lists the commands for bypass, scale, rules, status and health, and links to the runbook.
- **AC-6** A viewer sees the same page as a manager: it has no control that changes anything.

## Interfaces and files

- `src/Dashboard/Api/ConfigController.php`, and its route in `DashboardRoutes.php`.
- `src/DynamicThrottle.php`: `scopeNames()`, `@internal`, beside `isStrict()`.
- `resources/dashboard/pages/settings/`: `index.tsx`, `health-card.tsx`, `config-card.tsx`, `cli-card.tsx`, and their tests.
- `resources/dashboard/lib/settings-page.ts`: the config's type and query, and what each check looks for.

## Out of scope

- Changing the configuration from the dashboard: it lives in the app's files and environment, which deploys own (ADR-0028).
- Running the commands from the page.
- The Overview's emergency controls, which stay there (SPEC-021).

## Edge cases and failure modes

- **Redis is down:** the health card shows the store check's error, and the configuration card still shows, since `api/config` reads neither store.
- **The settings or audit table is missing:** the checks that read them say so; the page still shows.
- **A config value is huge,** such as a long `except` list: the card wraps it, and shows all of it.
- **`config:cache` is stale:** the page shows what the servers run with, which is the point: `api/config` reads the config the app booted with.

## End-to-end verification

- `npm run test`, `npm run typecheck`, `npm run lint`, `npm run build`, `npm run budget`
- `composer test -- --filter=Config`, `composer test`, `composer analyse`
- In the Workbench app:
  - open Settings, and see every check and the configuration;
  - stop Redis, and see the store check's error while the configuration still shows.

## Decisions

The maintainer vetoes any of these in the PR (ADR-0027).

1. **The page only reads.** The configuration lives in files and the environment, which deploys own, and the emergency changes stay on the Overview, where the dashboard opens.
2. **One endpoint for the configuration,** `GET api/config`, rather than a field of `api/health`: the health report runs every check, and the configuration should show even when the checks cannot run.
3. **The whole package config, as resolved,** not a chosen subset, so the page shows exactly what servers run with. The package keeps no secret in its config; a key an app adds that looks like one is hidden anyway, and a value JSON cannot hold shows as its type.
4. **Viewers see the configuration,** as they see the health report: neither holds a secret, and both help whoever is on call.
5. **What each check looks for lives in the page,** from the health guide's table, since the report's message says what was found and what to do, not what the check is for.

## PRs

| PR | Branch | Adds | Covers |
|---|---|---|---|
| 1 | `phase-5/spec-031-1` | This spec | Decisions |
| 2 | `phase-5/spec-031-2` | `api/config`, and the page with the configuration card | AC-1, AC-2, AC-4, AC-6 |
| 3 | `phase-5/spec-031-3` | The health and CLI cards, the guide, the changelog and the spec's close | AC-3, AC-5 |

## Open questions

None.
