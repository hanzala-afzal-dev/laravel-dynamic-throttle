# SPEC-036: A browser smoke test for releases

- Status: Done
- ADRs: ADR-0016, ADR-0028, ADR-0032
- Roadmap phase: 5 (an exit criterion still open), run on each release from now on

## Problem

Phase 5 has an exit criterion that never passed: "The end-to-end smoke test passes in a real browser: create a rule → send requests → see the block in Activity." The browser tests of #98 were closed without merging. Since then, every check of the dashboard runs without a browser: Vitest renders components in jsdom, and Pest tests the routes and the API. Nothing loads the real build in a real browser, under the real Content Security Policy, against a real server. ADR-0032 asks for "a browser test of the dashboard, once one exists" on each release's pull request (the maintainer asked for it on 2026-10-31).

## Behavior

`npm run smoke` runs two tests in Chromium, with Playwright, against the Workbench app counting in Redis:

1. **The Phase 5 flow.** In the dashboard, it creates an enforcing rule for a path of its own. It sends requests to that path until one gets a 429. It opens Activity and finds the block in the live feed, naming the rule. Then it deletes the rule.
2. **Every page opens.** It opens each page of the sidebar and checks its heading.

Each test fails when a page logs an error, throws, breaks the Content Security Policy, or gets an error answer from the dashboard.

CI runs it in a job of its own on each release's pull request into `main`, and on a few other events (decision 7).

## Decisions

1. **Playwright, with `@playwright/test`,** pinned in the root `package.json` as a dev dependency, Chromium only. The maintainer chose a Playwright smoke test in the Workbench app on 2026-10-31. Pest's browser plugin was the other option: it would add Composer dev dependencies to every PHP job, and the dashboard is TypeScript already.
2. **Two tests, not a suite.** The flow and the pages, enough to show that a release's dashboard loads and works from end to end. Wider browser tests are out of scope: #98 was closed, and the test cases live in the maintainer's sheet.
3. **Against the Workbench app, on Redis.** The live feed needs Redis or Valkey (SPEC-030). A fresh database each run, with the demo's data. Without `CI`, a server that already answers at the test's address is used as it is, so `docker compose up -d workbench` serves the test too. That is the way on Windows, and on a machine without PHP 8.3.
4. **It can run again on the same server.** Each run's rule has a name and a path of its own, `api/smoke/<run>`, on a new Workbench route that runs `dynamic.throttle`. The test deletes its rule at the end.
5. **The app's defaults, apart from Redis and the dashboard's own limit.** Rules still apply after `manifest.refresh_seconds` (2 seconds), so the test sends a request every half second until one gets a 429, for up to 20 seconds. The live feed polls every 5 seconds, so the test waits up to 15 seconds for the block. One run makes about 105 requests to the dashboard, close to its own limit of 120 a minute, and a second run within the minute passes it. So the server that `e2e/serve.sh` starts turns that limit off, through a Workbench switch (`WORKBENCH_SMOKE`). Pest covers the limit. That server also gets Redis keys of its own on each start (`DYNAMIC_THROTTLE_PREFIX`): its database is new, so its rules get the ids of the last start's rules, and the live feed names a block by its rule's id.
6. **A page guard,** in every test: a console error, an uncaught exception, or an error answer (4xx or 5xx) from the dashboard fails it. Chromium logs a Content Security Policy violation as a console error, so a violation fails too. A 429 from the dashboard says it is the dashboard's own limit, and what to do.
7. **Where CI runs it:** a "Browser smoke test" job, which "CI passed" waits for, on:
   - a release's pull request into `main` (ADR-0032 point 3);
   - pushes to `develop` and `main`, nightly runs and manual runs, as the full matrix does;
   - a pull request that changes the test, the Workbench app or the npm lock file.

   Other pull requests skip it, as ADR-0032 keeps their run small.
8. **CI builds the dashboard from its sources first.** On a release's pull request, the "Dashboard frontend" job already checks that `dist/` is that build, so the test covers what ships. On `develop`, it covers the code even before the Build dist workflow's pull request merges.
9. **PHP 8.5 and Laravel 13,** the newest the package supports, as a new app gets them. One combination is enough for a smoke test: the matrix runs everything else.
10. **No retries.** A flaky smoke test is a bug to fix, and the job can be run again. On a failure, CI keeps Playwright's report and trace for 7 days.
11. **In `e2e/` at the root,** with `export-ignore`, so nothing of it is in the Composer download. `npm run typecheck` and `npm run lint` cover it.

## Acceptance criteria

- **AC-1** Given Redis on `127.0.0.1:6379` and PHP 8.3 or later, `npm run smoke` builds the Workbench app afresh, serves it, and runs both tests in Chromium. Given a server that already answers at `SMOKE_URL` (`http://127.0.0.1:8000` by default), outside CI, it runs them against that server.
- **AC-2** The flow, as the Workbench app's guest, who manages: in the Rules form, a rule named `Smoke test <run>` for `api/smoke/<run>`, enforcing, 2 attempts a minute per IP. Requests to the path get a 429 within 20 seconds. Activity's live feed shows a "Blocked" row naming the rule within 15 seconds. Deleting the rule from its page, through its dialog, leads back to Rules, without the rule.
- **AC-3** Each of the seven pages of the sidebar (Overview, Rules, Exemptions, Activity, Explain, Audit log, Settings) opens from the sidebar with its heading.
- **AC-4** A test fails when its page logs a console error, throws, breaks the Content Security Policy, or gets an error answer (4xx or 5xx) from the dashboard.
- **AC-5** Against a server whose store keeps no live feed, such as `composer serve` on the database store, the flow fails with a message that says the test needs Redis, not with a timeout.
- **AC-6** CI runs the "Browser smoke test" job on the events of decision 7 and skips it on other pull requests. "CI passed" waits for it. On a failure, the job uploads Playwright's report.
- **AC-7** Nothing in `e2e/` is in the Composer download, and CI's check of the download passes.
- **AC-8** CONTRIBUTING.md says how to run the test, with Docker or with PHP, and its release steps name it. The roadmap's Phase 5 exit criterion points to this spec.

## Interfaces and files

- `e2e/playwright.config.ts`: Chromium, the address, the server, the report.
- `e2e/serve.sh`: builds the Workbench app afresh and serves it, counting in Redis.
- `e2e/guard.ts`: the page guard, as a Playwright fixture.
- `e2e/smoke.spec.ts`: the two tests.
- `workbench/app/Providers/WorkbenchServiceProvider.php`: the `api/smoke/{run}` route, and `WORKBENCH_SMOKE`, which turns the dashboard's own limit off.
- `docker/workbench/php.ini`: now also loaded by `e2e/serve.sh`.
- `package.json` and `package-lock.json`: `@playwright/test`, and the `smoke` script.
- `.github/workflows/ci.yml`: the job, and when it runs.
- `.gitattributes`, `.gitignore`, `tsconfig.json`, `eslint.config.js`: `e2e/` left out of the download, its output left out of git, its code checked.
- CONTRIBUTING.md, CLAUDE.md, the roadmap.

No public API changes: nothing here ships.

## Out of scope

- Wider browser tests of each page, its states and its errors. Vitest and Pest cover those.
- Other browsers, small screens, accessibility scans.
- The viewer's role and dashboard links in the browser. Pest covers who gets in.

## Edge cases and failure modes

- **Redis is not running:** `e2e/serve.sh` stops before it serves, and says to start it (`docker compose up -d redis`).
- **The port is taken in CI:** Playwright fails at once, rather than testing another server.
- **A server on the database store,** such as `composer serve`: AC-5.
- **The Docker demo's own traffic:** its blocks are in the same feed. The test looks only for its own rule's name.
- **Two runs within a minute against the Docker demo,** which keeps the dashboard's limit: the second may get a 429. The guard's message names the limit, and says to wait a minute.
- **A run that fails halfway** leaves its rule. The next run uses another path and name, so it is not affected. The Docker demo forgets it on its next start.

## End-to-end verification

- `docker compose up -d redis`, then `npm run smoke`: 2 passed.
- With the Docker demo: `docker compose up -d workbench`, then `npm run smoke`: 2 passed, twice in a row.
- Break the dashboard on purpose (an inline script in the page, a chunk missing from `dist/`, an API route that throws), and see the test fail for that reason.
- `npm run typecheck` and `npm run lint` pass.
- CI's "Browser smoke test" job passes on this spec's pull request, since it changes `e2e/`.

## PRs

One pull request: this spec, the test, the CI job and the docs. It has no `src` lines.

## Open questions

None. Veto any decision in the pull request.

## Notes from implementation

- **The dashboard's own limit.** The first runs on one server passed, then failed with 429s from the dashboard. One run makes about 105 requests to it, so even a single run in CI was close to the limit. Decision 5 now turns it off on `e2e/serve.sh`'s server.
- **The guard checks answers by their URL.** Chromium's console line for an error answer gives no URL, so the guard skips that line and names the answer from the response instead.
- **Checked that it fails** (each change undone after):
  - Activity's chunk removed from `dist/`: both tests fail, naming the 404 and the failed import.
  - An inline script in the dashboard's page: both fail with the Content Security Policy's message.
  - `api/feed` throwing: the flow fails, naming the 500s.
  - A server on the database store: the flow fails with "The smoke test needs Redis".
- **The Docker demo** could not be built in the cloud session: Docker Hub answered 429 to the image's pull. The same setup without Docker, with the demo's traffic and the dashboard's limit, passed twice in a row.
- **Chromium** in cloud sessions is preinstalled for another Playwright version, so `CHROMIUM_PATH` points the test at it. CI installs the one Playwright expects.
