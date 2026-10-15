# ADR-0018: Minimal dashboard — server-rendered Blade and vanilla JavaScript

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 4 (API) and 5 (UI)
- Supersedes: ADR-0014
- Amends: ADR-0003, ADR-0015, ADR-0016, ADR-0017 (see "Effect on other ADRs")
- Related: ADR-0004, ADR-0015, ADR-0017
- Amended: 2026-10-08, the dashboard ships with the package and with the first release, as Laravel Horizon's does, and its technology is kept (see the decision record)
- Amended by: [ADR-0028](0028-dashboard-react-shadcn.md) (2026-10-15): the UI is React with shadcn/ui, built ahead and shipped in the package; API first, assets through a package route and the strict CSP stay

## Context

- ADR-0014 chose a compiled React + TypeScript single-page app (Vite, compiled Tailwind, Vitest, a `dist/` folder shipped inside the package, and a CI check that `dist/` is fresh).
- On 2026-09-23 the maintainer set the goal to **minimal dashboard support**. The engine, the CLI and the JSON API are the product. The dashboard is a convenience on top of them.
- A Node build pipeline inside a PHP package has costs that a solo maintainer pays for the package's whole life: npm dependency updates and advisories, a `dist/` freshness check, and a second test toolchain.
- Every npm dependency adds supply-chain surface, in a package whose job is to protect the apps that install it.

## Decision

1. **API first (kept from ADR-0014).** A JSON API under the dashboard path covers every dashboard action:
   - rules
   - exemptions
   - settings
   - metrics
   - audit log
   - health
   - explain

   It stays internal (not covered by SemVer) until it is declared stable (ADR-0017).
2. **UI: server-rendered Blade views plus one small, hand-written JavaScript file.**
   - No JavaScript framework, bundler, CSS framework or npm dependency. No build step.
   - Pages render their data on the server. Reading works without JavaScript.
   - JavaScript only enhances the pages: sending changes to the JSON API, confirmation dialogs for dangerous actions (ADR-0015), and polling on Overview and Activity.
   - Business rules and validation live on the server. The JavaScript holds no logic that the server does not re-check.
3. **Assets** (one stylesheet, one script) are served through a package route with a content hash in the URL and long cache headers. There is **no `vendor:publish` step for assets**, so an upgrade can never leave stale published assets behind (same reasoning as ADR-0014).
4. **Strict Content Security Policy by default.**
   - No inline scripts, no inline styles, no inline event handlers, no `eval`.
   - Dashboard responses send `Content-Security-Policy` with `default-src 'none'` and only `'self'` for scripts, styles, images, connections and forms, plus `frame-ancestors 'none'` and `base-uri 'none'`.
   - They also send `X-Content-Type-Options: nosniff` and `Referrer-Policy: same-origin`.
   - If the host app already set a `Content-Security-Policy` header on the response, the package leaves it alone.
5. **Pages** (unchanged from ADR-0014):
   - Overview (health, emergency controls)
   - Rules (list, create/edit, dry-run → promote)
   - Exemptions
   - Activity (blocks, would-blocks, top offenders)
   - Audit log
   - Explain (simulate a request and see which rules match)
6. **Look:** a small stylesheet with system fonts, CSS custom properties and `prefers-color-scheme` for dark mode. Numbers are shown in tables. Any small charts are server-rendered SVG, with no chart library.

## Reasoning

- It matches the "minimal dashboard" goal while keeping ADR-0014's strongest points: API first, no requirements on the host app, and assets served through a route.
- Zero npm dependencies means zero npm advisories to track. The dashboard's supply chain is the PHP one the package already has.
- A strict CSP is easy to hold when there is no inline code and no framework that needs `unsafe-eval`. `frame-ancestors 'none'` blocks clickjacking of admin actions such as "bypass all".
- Blade is familiar to every Laravel developer, so contributors can work on the dashboard without learning a separate stack.
- Tables and forms cover what the pages need. Rich charts are a nice-to-have, not the goal.

## Alternatives considered

- **React + TypeScript SPA (ADR-0014).** A richer UI, but it brings a Node build pipeline, npm dependencies and a `dist/` freshness check into the PHP package. Rejected for now. The API-first design keeps it possible later, ideally as a separate package.
- **Blade + Alpine.js** (ADR-0014's fallback). Alpine's standard build evaluates expressions with `new Function`, which needs `unsafe-eval` under CSP. Its CSP build limits what expressions can do. Either way it is a vendored dependency for little gain. Rejected.
- **htmx.** Small, but another vendored dependency to track for advisories. Its inline features (`hx-on`, `eval`) must be disabled to satisfy CSP. Plain `fetch()` covers the few interactive actions. Rejected.
- **Livewire, Inertia, Filament.** They put requirements on the host app (same reasons as ADR-0014). Rejected. A Filament plugin remains a post-1.0 option on top of the JSON API.
- **CLI and API only.** Too little for operators who want to see blocks at a glance. Rejected, and since 2026-10-08 not a fallback for the release either: the package is released only with its dashboard.

## Fallback

- If the UI proves too limited (users ask for charts, complex filtering or live views), build a richer frontend as a **separate package** on the same JSON API (React SPA or Filament plugin), instead of adding a build step to this package.
- If serving assets through PHP proves slow on some hosts, add an optional `vendor:publish --tag=dynamic-throttle-assets` path.
- If the dashboard phase slips, the release waits for it: the package is released only with its dashboard (decided on 2026-10-08, see the decision record).
- **Revisit when:** three months after 0.1.0, the first release, which ships the dashboard. Review dashboard-related issues and feature requests.

## Consequences

- Good: no Node toolchain in the package or in CI, a very small attack surface, a strict CSP by default, and read-only pages that work without JavaScript.
- Bad: a plainer look than an SPA, no rich charts, and some duplication between Blade view data and JSON API responses.

## Verification

This replaces ADR-0014's verification and the **Frontend** row of ADR-0016's test layers:

- Pest feature tests:
  - Every page renders for a viewer.
  - Every dashboard response has the strict CSP header.
  - No rendered page contains an inline `<script>` body, a `style=` attribute or an `on*=` event handler attribute.
- The authorization matrix (ADR-0015) covers page routes and API routes.
- End-to-end smoke test in Pest: create a rule through the API → send requests to a limited route → the block appears on the Activity page.
- The dashboard stays reachable under a `*` rule (ADR-0015).
- The repository contains no `package.json`. A CI check enforces this.
- If the script grows helper functions worth unit-testing, they are tested with Node's built-in test runner (`node --test`), which needs no npm packages.

## Effect on other ADRs

Accepted ADRs are not rewritten, so these lines still mention the SPA. Read them as follows:

| ADR | Text that assumed the SPA | Read as |
|---|---|---|
| ADR-0003, levels table | Code lives in `src/, dashboard/` | Code lives in `src/` and `resources/` (Blade views, one CSS file, one JS file) |
| ADR-0003, "When steps can be skipped" | Vitest for component logic | Pest feature tests for pages; `node --test` only if the script grows helpers |
| ADR-0015, decision 2 | "the SPA uses Laravel's XSRF cookie" | The dashboard script sends Laravel's XSRF token the same way |
| ADR-0016, tools and test layers | Vitest and a Playwright/Nightwatch end-to-end test | Pest feature tests and the Pest end-to-end smoke test above |
| ADR-0017, release process and verification | The release builds `dist/` and fails when it is stale | There is no `dist/`; the release fails when a `package.json` exists instead |

## Decision record

Amended on 2026-10-08. The maintainer decided that the package is not released until it has a proper dashboard for managing throttling at runtime, shipped with the package as Laravel Horizon's is, and asked which technology to build it with. The first release, 0.1.0, now comes at the end of Phase 5, with the dashboard ([the roadmap](../roadmap.md)).

The technology stays as decided above: server-rendered Blade and one hand-written JavaScript file, with no build step. What "like Horizon" asks for is how the dashboard is delivered and how it feels, which this choice can give, not the framework behind it:
- **Delivered like Horizon's:** installed with the package, served at its own path (`/dynamic-throttle` by default), behind a gate (ADR-0015), with no separate app to deploy. The stylesheet and the script are served by a package route (point 3), so there is no asset publishing step either.
- **Feels like Horizon's:** a sidebar to move between the pages, an overview whose numbers and charts refresh every few seconds, and every rule, exemption and emergency switch managed in the browser, with a confirmation for the dangerous ones (ADR-0015). Charts are server-rendered SVG, refreshed by polling (point 6).

The alternatives, for a dashboard that ships inside a package:
- **Vue, as Horizon and Telescope use.** A richer component model, but it brings npm, a build step and compiled assets into the repository and every release, and a JavaScript stack beside the PHP one for a single maintainer to keep current. The pages are tables, forms and a few charts, which server rendering covers. Rejected, for the reasons React was rejected above.
- **React.** The same costs as Vue, and none of Laravel's own dashboards use it. Rejected.
- **Livewire, as Pulse uses.** Server-driven like Blade, but it makes the host app install Livewire, at a version the package must match, and it is built on Alpine, which needs `unsafe-eval` under CSP in its standard build (see Alpine above). Pulse can require it because it is part of Laravel itself. Rejected.

If users need more than server rendering gives after 0.1.0, the fallback above still holds: a richer frontend as a separate package on the same JSON API.

On 2026-10-15, starting Phase 5, the maintainer asked for a modern dashboard with shadcn/ui components and proper charts, and chose React with shadcn/ui, built ahead and shipped in the package, so users still install nothing ([ADR-0028](0028-dashboard-react-shadcn.md)).
