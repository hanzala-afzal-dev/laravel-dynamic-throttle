# SPEC-020: The dashboard's foundation

- Status: Approved
- ADRs: ADR-0015, ADR-0018, ADR-0028
- Roadmap phase: 5

## Problem

The JSON API is done, but nothing draws a page. Before the first page, the dashboard needs its frame: the React toolchain and the build that ships in the package (ADR-0028), the routes that serve the page and its assets, the strict Content Security Policy, the layout every page shares, and the client that talks to the API.

## Behavior

### The build (ADR-0028)
- **Sources** live in `resources/dashboard/`: React 19, TypeScript, Tailwind CSS 4 and shadcn/ui components, built by Vite. Routing by wouter, data by TanStack Query.
- **`npm run build`** writes `dist/`: the hashed JavaScript and CSS, and Vite's manifest, `dist/.vite/manifest.json`. `dist/` is committed; the sources, `package.json` and its lock file are left out of the Composer download (decision 1).
- **CI checks the frontend:** type check, lint, Vitest, a fresh build that leaves `dist/` unchanged, the first page's JavaScript under 300 KB gzipped, and `npm audit` with no high or critical advisory in what ships.

### The routes
- **The page shell:** `GET {path}` and any path under it that is not `api/…` or `assets/…`, such as `{path}/rules/12`, answers the same small HTML page, so the browser's own routes reload. It needs either gate (SPEC-018), and is named `dynamic-throttle.page`. The shell holds:
  - the root element, with the dashboard's base path in a `data-` attribute;
  - the hashed stylesheet and module script, from Vite's manifest;
  - the CSRF token and a fresh nonce, each in a `<meta>` tag;
  - nothing inline: no `<script>` body, no `style` attribute, no `on…` attribute.
- **Its Content Security Policy:** `default-src 'none'; script-src 'self'; style-src 'self' 'nonce-…'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'`, plus `Referrer-Policy: same-origin`. The nonce is new on each request and lets libraries that insert a `<style>` element at run time do so (decision 3). An app that already set a `Content-Security-Policy` on the response keeps its own (ADR-0018 point 4).
- **The assets:** `GET {path}/assets/{file}` serves a file from `dist/`, with its type, `Cache-Control: public, max-age=31536000, immutable` and `nosniff`. Without the gates and without a session, so browsers and proxies cache them: they are the package's public code (decision 2). Only a name Vite's manifest lists is served; any other answers 404.
- **Without a build,** as in a fresh clone, the shell answers 503: "The dashboard is not built: run npm ci and npm run build."
- **No rule applies** to the shell or the assets, by name and by path (SPEC-018 AC-9).

### The frame every page shares
- **A sidebar** with the pages: Overview, Rules, Exemptions, Activity, Explain, Audit log, Settings. Each page is a placeholder until its spec; an unknown path shows "Page not found".
- **A header** with the app's environment, the user's role (Viewer or Manager, from `api/me`), and a theme toggle: system, light or dark, remembered by the browser. The system theme applies before any script runs, from CSS alone.
- **A banner on every page while bypass or a scale factor is on,** with its end time, and for a manager a button that turns it off (with the confirmation the API asks for).
- **The API client** sends JSON with the CSRF token, and turns errors into messages: 422 under each field, 409 as a confirmation to accept, 403 "You may not do this", 419 "Your session expired: reload the page", 429 "Too many requests: wait a moment", 503 with the server's message.
- **Live data polls every 5 seconds** while the tab is visible, and stops while it is hidden.
- **Times** show in the browser's time zone, with UTC on hover.
- **Viewers** see no button that changes anything; managers see them all.

## Acceptance criteria

### Server (Pest)
- **AC-1** `GET /dynamic-throttle`, `/dynamic-throttle/rules` and `/dynamic-throttle/rules/12` answer the shell, 200 `text/html`, for a viewer; a guest in production gets 403. `GET /dynamic-throttle/api/nope` is not the shell: it answers 404.
- **AC-2** The shell holds the root element with `data-base="/dynamic-throttle"` (or the configured path), the stylesheet and the module script named in the manifest under `/dynamic-throttle/assets/`, the CSRF token and the nonce in `<meta>` tags, and no inline script body, `style` attribute or `on…` attribute.
- **AC-3** The shell's `Content-Security-Policy` is exactly the policy above, its nonce is the one in the `<meta>` tag and differs on every request, and it comes with `Referrer-Policy: same-origin`. A response the app already gave a CSP keeps it.
- **AC-4** `GET /dynamic-throttle/assets/{file}` for a file the manifest lists answers it with `application/javascript` or `text/css`, `Cache-Control: public, max-age=31536000, immutable` and `nosniff`, to a guest, and sets no cookie. A file the manifest does not list, `../composer.json` encoded or not, and the manifest itself answer 404.
- **AC-5** With no manifest, the shell answers 503 with the message to build.
- **AC-6** Every route named `dynamic-throttle.*` stays reachable under a `*` rule, the shell and the assets included (the Phase 4 exit test).

### Frontend (Vitest)
- **AC-7** The layout shows the seven pages in the sidebar, the active one marked; an unknown path shows "Page not found".
- **AC-8** The header shows "Viewer" or "Manager" from `api/me`, and the theme toggle goes system → light → dark → system, sets the class on `<html>`, and remembers the choice.
- **AC-9** With bypass on, the banner says until when; a manager sees "Turn off", which sends `PUT api/settings/bypass` with `state: off`; a viewer sees no button. With nothing on, no banner.
- **AC-10** The client sends `Accept: application/json` and the CSRF token, and gives each error status its message; a 422 keeps the fields' messages.
- **AC-11** Live queries poll every 5 seconds and not while the document is hidden.
- **AC-12** A time shows in the browser's zone with its UTC time as the title.

### CI
- **AC-13** CI fails when the build changes `dist/`, when the first page's JavaScript exceeds 300 KB gzipped, on a high or critical advisory in a shipped dependency, on a type or lint error, or when a Vitest test fails. The Composer download holds `dist/` and `resources/views/`, and none of `resources/dashboard/`, `package.json` or the lock file.

## Interfaces and files

- `package.json`, `package-lock.json`, `vite.config.ts`, `tsconfig.json`, `eslint.config.js`, `components.json` (shadcn/ui), `.nvmrc` (22).
- `resources/dashboard/`: `main.tsx`, `app.tsx`, `lib/` (API client, polling, time, theme), `components/ui/` (shadcn/ui), `components/layout/`, `pages/`, `styles.css`, and tests next to the code (`*.test.tsx`).
- `dist/`: the build, committed.
- `resources/views/dashboard.blade.php`: the shell.
- `src/Dashboard/`: `PageController.php`, `AssetController.php`, `ViteManifest.php` (reads the manifest, once per process), and `ContentSecurityPolicy.php` (the nonce and the header); `DashboardRoutes.php` registers the shell and the assets, after the API.
- `.github/workflows/ci.yml`: a `frontend` job, and the Composer download check allows `dist/`. The "no npm" check goes (ADR-0028).
- `.gitattributes`: the sources and the toolchain are export-ignored.

## Out of scope

- The pages' content (SPEC-021 to SPEC-026, SPEC-031).
- The end-to-end browser test (the Phase 5 exit).
- A setting to load the dashboard's assets from a CDN.

## Edge cases and failure modes

- **A contributor edits a page and forgets to rebuild:** CI's fresh build differs from `dist/`, and fails.
- **An app's `web` group sets its own CSP** through a middleware: the response already has one, so the package keeps it, and the app answers for it.
- **The dashboard on its own domain** (`dashboard.domain`): the assets are on the same domain, so `'self'` covers them.
- **A browser with an old asset cached** after an upgrade: the hashed names differ, so it fetches the new one.
- **The page is cached by a proxy:** the shell is `no-store`, like every dashboard response (SPEC-018).
- **JavaScript off:** the shell says the dashboard needs JavaScript, in a `<noscript>`.

## End-to-end verification

- `npm ci && npm run build && npm run test && npm run typecheck && npm run lint`
- `composer test -- --filter=Dashboard`, `composer test`, `composer analyse`
- In the Workbench app (`composer serve`): open `/dynamic-throttle`, switch the theme, turn bypass on with the CLI and see the banner, and check the browser's console shows no CSP error.

## Decisions

The maintainer vetoes any of these in the PR (ADR-0027).

1. **`dist/` at the package's root, the sources in `resources/dashboard/`,** left out of the Composer download with the toolchain's files, so users get only the build.
2. **The assets need no gate and no session,** so they cache: they are the package's public code, as on GitHub. Only names in the manifest are served, so nothing else in `dist/` or the package can be read.
3. **A per-request nonce for runtime `<style>` elements,** rather than `'unsafe-inline'`: Radix's dialogs insert one to lock scrolling. React's own styles go through the DOM, which CSP allows.
4. **wouter for routing and TanStack Query for data,** small libraries that keep the first page under budget: wouter is about 2 KB; TanStack Query gives polling that pauses in hidden tabs, caching and retries.
5. **The system theme comes from CSS,** so a dark system shows no light flash before the script runs; the toggle overrides it with a class on `<html>`.
6. **No inline data in the shell:** the base path goes in a `data-` attribute, the token and the nonce in `<meta>` tags, and the rest comes from the API.

## PRs

| PR | Branch | Adds | Covers |
|---|---|---|---|
| 1 | `phase-5/spec-020-1` | This spec | Decisions |
| 2 | `phase-5/spec-020-2` | The toolchain, shadcn/ui's base, a first build in `dist/`, and the CI job | AC-13 |
| 3 | `phase-5/spec-020-3` | The shell, the asset route and the CSP | AC-1 to AC-6 |
| 4 | `phase-5/spec-020-4` | The layout, the theme, the API client, polling, the banner, the guide, the changelog, the review's fixes and the spec's close | AC-7 to AC-12 |

## Open questions

None.
