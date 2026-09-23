# ADR-0018: Minimal dashboard — server-rendered Blade and vanilla JavaScript

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 4 (API) and 5 (UI)
- Supersedes: ADR-0014
- Amends: ADR-0016 (frontend test layer)
- Related: ADR-0004, ADR-0015, ADR-0017

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
- **CLI and API only.** Too little for operators who want to see blocks at a glance. It stays the fallback.

## Fallback

- If the UI proves too limited (users ask for charts, complex filtering or live views), build a richer frontend as a **separate package** on the same JSON API (React SPA or Filament plugin), instead of adding a build step to this package.
- If serving assets through PHP proves slow on some hosts, add an optional `vendor:publish --tag=dynamic-throttle-assets` path.
- If the dashboard phase slips, ship the release with CLI and API only (ADR-0014's fallback still holds).
- **Revisit when:** three months after 0.2.0, review dashboard-related issues and feature requests.

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
