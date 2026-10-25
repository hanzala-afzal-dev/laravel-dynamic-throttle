# SPEC-033: Say when nobody can open the dashboard

- Status: Done
- ADRs: ADR-0015, ADR-0017
- Roadmap phase: the betas, before 0.1.0

## Problem

Outside the `local` environment, nobody can open the dashboard until the app defines `viewDynamicThrottle` or `manageDynamicThrottle` (ADR-0015, SPEC-018 decision 2), as with Horizon. An app that skips that step gets a bare 403 in production, and nothing tells it why. Raised by the maintainer on 2026-10-19 (the roadmap's "Dashboard access in production").

## Behavior

A new health check, `dashboard`, in `dynamic-throttle:health` and on the dashboard's Settings page. It reads what the dashboard reads to let someone in: whether the app defines either gate, and the environment.

- **The dashboard is off** (`dashboard.enabled` false): skipped.
- **Neither gate is defined, outside `local`:** a warning, "Nobody can open the dashboard: define viewDynamicThrottle or manageDynamicThrottle", or turn the dashboard off. `--strict` then exits 1, so a deploy script catches a forgotten gate, and an app that wants no dashboard in production turns it off, which skips the check.
- **Neither gate is defined, in `local`:** ok. Anyone gets in there, guests too, and the message says that nobody will elsewhere.
- **A gate is defined:** ok, naming the gates that decide. With `viewDynamicThrottle` alone, it also says that nobody can change anything from the dashboard, since a gate the app left out denies.

The 403 page stays as it is: it tells a visitor nothing about the app's setup.

## Decisions

1. **The id is `dashboard`, listed after `apcu`,** with the checks of the setup, before the route checks.
2. **A warning, not an error.** The package still limits requests without the dashboard, and the CLI does everything the dashboard does. `--strict` catches it for deploys.
3. **A gate is defined when `Gate::has()` says so,** as for the dashboard (`Gates`). A `Gate::before` callback alone does not count: the dashboard ignores it while neither gate is defined, so the check does too, and says nobody can open it.
4. **It cannot say whether a defined gate lets anyone in:** a callback decides per user. It names the gates that decide, nothing more.
5. **Exact messages:**
   - warning: `Nobody can open the dashboard: define viewDynamicThrottle or manageDynamicThrottle, or turn the dashboard off with DYNAMIC_THROTTLE_DASHBOARD=false (docs/guide/dashboard.md#who-gets-in).`
   - ok, `local`, no gate: `In the local environment, anyone can open the dashboard, guests too. Elsewhere, nobody can until the app defines viewDynamicThrottle or manageDynamicThrottle.`
   - ok, both gates: `viewDynamicThrottle and manageDynamicThrottle decide who opens the dashboard.`
   - ok, `manageDynamicThrottle` alone: `manageDynamicThrottle decides who opens the dashboard, and who changes things there.`
   - ok, `viewDynamicThrottle` alone: `viewDynamicThrottle decides who opens the dashboard. Nobody can change anything there until the app defines manageDynamicThrottle.`
   - skipped: `The dashboard is off (dynamic-throttle.dashboard.enabled).`

## Acceptance criteria

- **AC-1** Given the dashboard on, no gate defined and the environment `production`, the check warns with the message of decision 5, and `dynamic-throttle:health --strict` exits 1. The dashboard answers a guest and a signed-in user 403 there, as the check says.
- **AC-2** Given no gate defined and the environment `local`, the check is ok with the `local` message, and a guest opens the dashboard.
- **AC-3** Given one gate or both defined, in `production`, the check is ok with the message for those gates.
- **AC-4** Given only a `Gate::before` callback that allows everyone, in `production`, the check warns, and the dashboard answers 403, as it does.
- **AC-5** Given `dashboard.enabled` false, the check is skipped with its message.
- **AC-6** The report lists `dashboard` after `apcu`, and the dashboard's `GET api/health` includes it.

## Interfaces and files

- `src/Support/Health/Checks/DashboardCheck.php`: the check. Listed in `HealthChecks`.
- `src/Dashboard/Gates.php`: `defined(): bool`, which `abilities()` and the check share.
- `resources/dashboard/lib/settings-page.ts`: the check's purpose on the Settings page, and `dist/` rebuilt.
- `docs/guide/health.md`, `docs/guide/dashboard.md`, `README.md` (the install step), `CHANGELOG.md`.

No config key, command, event or schema changes. A new check id is not a breaking change (ADR-0017).

## Out of scope

- A clearer 403 page: it would tell any visitor how the app is set up.
- SPEC-034, one-time links for apps without a login.

## Edge cases and failure modes

- **Environments named otherwise,** such as `staging` or `testing`, are not `local`: the check warns there, as the dashboard turns everyone away.
- **The dashboard's path is invalid:** the `config` check reports it. This check still answers by the gates.
- **The command line and the web server** define the same gates, in the app's service providers, so both say the same.

## End-to-end verification

- `composer test -- --filter=HealthDashboard`, then `composer test` and `composer analyse`.
- `npm run test`, `npm run build`.
- In a fresh app with `APP_ENV=production` and no gate: `php artisan dynamic-throttle:health --strict` exits 1 with the warning.

## Open questions

None.
