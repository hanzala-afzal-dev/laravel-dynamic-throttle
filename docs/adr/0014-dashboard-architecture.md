# ADR-0014: Dashboard architecture

- Status: Superseded by [ADR-0018](0018-minimal-dashboard.md)
- Date: 2026-09-23
- Roadmap phase: 4 (API) and 5 (UI)
- Related: ADR-0015

## Context

The first draft used a single Blade page with the Tailwind CDN script. That script is not meant for production, and a pasted markdown link broke its `src` attribute. The dashboard is the package's shop window, so it needs to look good, work without the host app having a JavaScript toolchain, and survive package upgrades.

## Decision

1. **API first.** A JSON API under the dashboard path covers every dashboard action:
   - rules
   - exemptions
   - settings
   - metrics
   - audit log
   - health
   - explain

   It is marked internal (not covered by SemVer) until 1.x.
2. **UI:** a compiled **React + TypeScript** single-page app shipped inside the package, built with Vite and compiled Tailwind. This matches the maintainer's strongest stack.
3. **Asset serving through a package route**, with content-hashed filenames and long cache headers. **No `vendor:publish` step for assets.** This avoids the classic "published assets are out of date after `composer update`" problem.
4. **CSP-friendly.** No inline scripts; nonce support when the host app uses one.
5. Built assets live in `dist/`, produced by CI at release. A CI check fails if `dist/` does not match a fresh build.
6. **Pages:**
   - Overview (health, emergency controls)
   - Rules (list, create/edit, dry-run → promote)
   - Exemptions
   - Activity (blocks, would-blocks, top offenders)
   - Audit log
   - Explain (simulate a request and see which rules match)

## Reasoning

- API first means the UI, the CLI and future integrations (Filament plugin, Pulse card) share one tested backend.
- A compiled SPA needs no Node, React, Livewire or Inertia in the host app. Laravel Horizon and Telescope follow the same pattern.
- Serving assets through a route removes a whole class of upgrade bugs.

## Alternatives considered

- **Blade + Alpine.** Simplest, but a weaker UI for tables, filters and charts. Kept as the fallback.
- **Livewire.** Forces a dependency and version constraints on the host app. Rejected.
- **Inertia.** Requires the host app to use Inertia. Rejected.
- **Filament plugin as the only UI.** Limits users to Filament apps. Planned as an optional extra package after 1.0.

## Fallback

- If the SPA falls behind schedule, ship 0.x with CLI + API only, then Blade views on the same API.
- If asset serving through PHP proves slow on some hosts, add an optional `vendor:publish --tag=dynamic-throttle-assets` path.
- **Revisit when:** Phase 5 exceeds its estimate by more than 50%.

## Consequences

- Good: a polished UI, no host-app requirements, and a reusable API.
- Bad: a Node build pipeline inside a PHP package, plus JavaScript dependency updates to maintain.

## Verification

- Vitest component tests.
- One end-to-end smoke test: create a rule → send requests → see the block in Activity.
- CI checks that `dist/` is fresh.
- The page loads under a strict CSP in the Workbench app.
