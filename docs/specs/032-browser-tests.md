# SPEC-032: Browser tests and the test case catalog

- Status: In progress
- ADRs: ADR-0016, ADR-0027, ADR-0028
- Roadmap phase: 6

## Problem

The dashboard is tested in parts. Pest checks its routes and its JSON API, and Vitest checks its components against a mocked API. Nothing runs it in a real browser against a real server. So nothing checks that its pages obey their own Content Security Policy, that a 409 really comes back as a dialog, that polling shows a change made elsewhere, that the live feed shows real blocks, or that a viewer really sees no button that changes things. Nothing checks accessibility or small screens either. ADR-0028 planned one Playwright smoke test; it ran by hand at Phase 5's exit and never reached the repository.

Beta testers, and agents that test the package in their own apps, have no single list of what to try and what must happen.

## Behavior

- **A test case catalog,** `docs/testing/test-cases.md`, lists every behavior a user can see: the install, limiting, matching, scopes, exemptions, the emergency controls, failures, the Artisan commands, and each dashboard page, with how it feels to use, its accessibility and small screens. Each case has an id, its steps, the expected result, and where it is checked: the browser suite, Pest, Vitest, CI or by hand.
- **A browser suite,** in `e2e/`, runs the dashboard in Chromium with Playwright, against the Workbench app as `testbench serve` runs it, on Redis. It covers the cases the catalog marks E2E, and each test's name starts with its case's id: `test('RUL-07: …')`.
- **Locally,** `npm run e2e` builds a fresh Workbench database, starts the server, and runs the suite. It needs Redis: `docker compose up -d redis`.
- **In CI,** a "Browser tests" job runs it on every pull request and push to main, and keeps Playwright's report, screenshots and traces when a test fails.

## Acceptance criteria

- **AC-1** `npm run e2e` builds a fresh Workbench database, serves it on Redis, runs the suite in Chromium, and passes. Run twice in a row, it passes both times.
- **AC-2** A console error, an uncaught error or a Content Security Policy violation on a page fails the test that opened it, and the failure names it.
- **AC-3** CI's "Browser tests" job runs on pull requests and on pushes to main, keeps the report and the traces when it fails, and `CI passed` waits for it.
- **AC-4** Every case the catalog marks E2E has a test whose name starts with its id, and no test names a case the catalog lacks. A check script fails CI when they differ.
- **AC-5** The suite leaves no emergency setting on: afterwards, `dynamic-throttle:status` shows bypass and scale off, even when a test failed half-way.
- **AC-6** The cases marked E2E in the catalog pass, in the PRs the table below names.

## Interfaces and files

- `package.json`: the `@playwright/test` and `@axe-core/playwright` dev dependencies, pinned, and the `e2e` script.
- `playwright.config.ts`: Chromium, the Workbench server, one worker, traces and screenshots on failure.
- `e2e/`: the tests (`*.spec.ts`) and their fixtures (`fixtures.ts`): the page guard, the dashboard's JSON API with its CSRF token, rules made for one test, and Artisan.
- `workbench/app/Providers/WorkbenchServiceProvider.php`: a route `e2e/{path}`, which runs `dynamic.throttle`, so each test limits a path of its own.
- `.github/workflows/ci.yml`: the `browser` job, with a Redis service, and `CI passed` waits for it.
- `scripts/e2e-cases.mjs`: compares the catalog's E2E ids with the tests' (AC-4).
- `.gitattributes`: `e2e/` and `playwright.config.ts` stay out of the Composer download. `.gitignore`: Playwright's output.
- `tsconfig.json`, `eslint.config.js`: type check and lint `e2e/`.
- `CONTRIBUTING.md`, `CLAUDE.md`: `npm run e2e`.
- `docs/testing/test-cases.md`: the catalog.

## Out of scope

- Firefox and WebKit. Chromium shows whether the dashboard works; other engines can come if users report problems in them.
- Screenshot comparisons (decision 8).
- Testing in a user's own app: the catalog's Manual cases are for that.
- Load testing, a separate Phase 6 item.

## Edge cases and failure modes

- **A rule takes up to 2 seconds to reach the server** (`manifest.refresh_seconds`): a test waits for the status it expects, polling, rather than sleeping a fixed time.
- **Pages poll every 5 seconds:** assertions wait for what they expect, up to Playwright's timeout, so a slow poll never fails a test.
- **A test fails with bypass on:** a hook after each test turns bypass and scale off (AC-5).
- **The demo's made-up numbers use the real clock:** no test depends on the hour or the day.
- **Port 8123 is taken:** `E2E_PORT` picks another. Locally, a server already running on it is reused; CI always starts its own.
- **Redis is not running:** the suite stops before it starts, saying to run `docker compose up -d redis`.
- **No downloadable browser,** as in cloud sessions: `E2E_CHROMIUM` names a Chromium already on the machine.

## End-to-end verification

- `docker compose up -d redis`, then `npm run e2e`, twice
- `npm run typecheck`, `npm run lint`, `npm run test`
- `composer test`, `composer analyse`
- `node scripts/e2e-cases.mjs`

## Decisions

The maintainer vetoes any of these in the PR (ADR-0027).

1. **Playwright, through `@playwright/test`.** Pest's browser plugin also drives Playwright, but it needs PHP 8.4 while the package supports 8.3, and as a Composer dev dependency it would install on every CI job. The dashboard already has a Node toolchain, and `@playwright/test` brings the report, traces and retries.
2. **The tests live in `e2e/`, at the root.** `tests/` holds PHP's tests, which ESLint skips, and Vitest only looks in `resources/dashboard/`. Like every dev tool, `e2e/` stays out of the Composer download.
3. **They run against the Workbench app,** as `testbench serve` runs it, on Redis, with the demo's data. Its gates give the dashboard's two roles: a guest or `manager@example.com` manages, and `viewer@example.com`, signed in at `/_workbench/login/viewer@example.com`, only views. The live feed and the offenders need Redis, and Redis is what production should run. Each run builds a fresh database first.
4. **Each test makes its own data,** on its own path: it creates rules on `e2e/<case id>…`, a Workbench route that runs `dynamic.throttle`, and names them with its case id. No test assumes an empty dashboard; one that counts compares before and after. One worker runs the tests, since bypass and scaling are shared by all; the tests that change them turn them off afterwards.
5. **A page guard** fails any test whose page logs a console error, throws, or reports a CSP violation (AC-2). The dashboard runs under a strict policy, so a violation is a bug, even when the page still looks right.
6. **Chromium only,** at 1280 × 800, in UTC and `en-US`, so times and numbers read the same everywhere. A second project, at 390 × 844, runs the small-screen cases (MOB).
7. **Accessibility with axe-core,** through `@axe-core/playwright`: every page, light and dark, against WCAG 2.1 A and AA, failing on serious and critical violations. A first scan found one: the light theme's "Warnings" health status is #fe9a00 on white, 2.13:1, where large text needs 3:1. PR 5 fixes it, test first.
8. **No screenshot comparisons.** Fonts and anti-aliasing differ between machines and make them flaky. Playwright keeps a screenshot and a trace of each failure instead.
9. **Not in `composer test`:** the suite needs Node, a browser and Redis. `npm run e2e` runs it locally, and CI in a job of its own, which `CI passed` waits for.
10. **CI installs the browser** that the pinned Playwright expects (`npx playwright install --with-deps chromium`). A machine that cannot download one, such as a cloud session, names its own in `E2E_CHROMIUM`.
11. **A bug the suite finds is fixed test-first:** the failing browser test is committed first (`test:`), then the fix, as for any bug (CLAUDE.md).
12. **The catalog is for people too.** Its Manual cases are what a beta tester or an agent checks in a real app; its ids are what a bug report can name.

## PRs

| PR | Branch | Adds | Covers |
|---|---|---|---|
| 1 | `phase-6/spec-032-1` | This spec, the test case catalog, ADR-0028's note | Decisions |
| 2 | `phase-6/spec-032-2` | The harness, the CI job, the frame, and the smoke flow: a rule made in the dashboard limits a route | AC-1 to AC-3, AC-5; SHL, LIM-01, LIM-08, LIM-10 |
| 3 | `phase-6/spec-032-3` | Overview, Rules and Exemptions | OVW, RUL, EXM; LIM-03, LIM-05, EMG-01, EMG-02, EMG-04, EMG-06 |
| 4 | `phase-6/spec-032-4` | Activity, Explain, Audit log, Settings, and access | ACT, EXP, AUD, SET, SEC; EMG-10 |
| 5 | `phase-6/spec-032-5` | Accessibility, the keyboard, small screens, the fixes they need, the case check, and the spec's close | A11, UXP, MOB; AC-4, AC-6 |

## Open questions

None.
