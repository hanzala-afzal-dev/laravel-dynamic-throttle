# ADR-0028: Dashboard UI — React and shadcn/ui, built ahead and shipped in the package

- Status: Accepted
- Date: 2026-10-15
- Roadmap phase: 5
- Amends: ADR-0018 (points 2 and 6), ADR-0016, ADR-0017 (see "Effect on other ADRs")
- Amended: 2026-10-30 by ADR-0030: pull requests leave `dist/` alone, and a workflow builds it after each merge to `main` and opens a pull request with it.
- Related: ADR-0014, ADR-0015, ADR-0018

## Context

- ADR-0018 chose server-rendered Blade and one hand-written JavaScript file, with no npm and no build step, for a "minimal dashboard".
- On 2026-10-08 the goal grew: the package is released only with a proper dashboard, delivered and feeling like Horizon's (ADR-0018's decision record).
- On 2026-10-15, starting Phase 5, the maintainer asked for a modern dashboard: shadcn/ui components, proper charts and summaries, and settings pages. Hand-written SVG charts and forms cannot reach that look and interaction at a reasonable cost.
- The JSON API (SPEC-017, SPEC-019) already serves every page, so only the UI layer changes.

## Decision

1. **UI: React with TypeScript, styled with Tailwind CSS and shadcn/ui components, charts with Recharts** (shadcn's chart components). shadcn/ui is copied into the source, not installed, so its components are ours to keep.
2. **Built ahead, shipped in the package.** Vite builds `resources/dashboard/` into `dist/`, which is committed. Users run `composer require` and nothing else: no Node, no npm, no React in their app, no `vendor:publish`.
3. **Kept from ADR-0018:**
   - API first: the pages read and change everything through the JSON API, which re-checks every rule (point 1).
   - Assets served by a package route, with a content hash in the URL and long cache headers (point 3).
   - A strict Content Security Policy (point 4): scripts and styles from `'self'` only, no inline script, no `eval`. Styles that libraries insert at runtime carry a per-request nonce the page hands them.
   - The pages of ADR-0018 point 5, plus Settings.
4. **One page shell.** A Blade view gives the browser the app's root element, the hashed asset links, the CSRF token and the nonce, and nothing else; React draws the pages. The browser's history drives the routes, under the dashboard's path.
5. **Budget:** the JavaScript the first page loads stays under 300 KB gzipped, and pages load their own code when opened. CI fails above it.
6. **The toolchain is ours only:** `package.json` and its lock file sit in the repository for contributors; `.gitattributes` keeps them and the sources out of the Composer download, which ships `dist/` only.

## Reasoning

- shadcn/ui gives a modern, accessible look (Radix primitives) with components we own, without a design dependency that can change under us.
- React with TypeScript is the maintainer's strongest stack (ADR-0014), so the dashboard stays maintainable by its owner.
- Shipping the build keeps the promise users care about: nothing to install or publish, and an upgrade can never leave stale assets behind. Horizon ships its dashboard the same way.
- A strict CSP is still possible: React sets styles through the DOM, which CSP allows, and the few libraries that insert `<style>` elements take a nonce.

## Alternatives considered

- **Keep ADR-0018 (Blade, hand-written JS, SVG charts).** No npm at all, but a plainer UI and slow, hand-made charts. Rejected for the look the maintainer asked for.
- **Livewire with Tailwind,** as Pulse does. Every app would install Livewire at a version we support, and its Alpine core needs a looser CSP unless run in a CSP-safe mode. Rejected, as in ADR-0018.
- **Vue,** as Horizon and Telescope use. Equally possible; React is the maintainer's stack and shadcn/ui's home. Rejected.
- **Build at release in CI instead of committing `dist/`.** Composer installs from the tag's tree, so the tag must hold the build; committing it, with a check that it is fresh, is the simplest way. Rejected.

## Fallback

- If npm advisories or upkeep cost too much, the dashboard can move to a separate package on the same JSON API, leaving the core free of a frontend toolchain.
- If the bundle outgrows its budget, split pages further, or replace Recharts with a smaller chart library.
- **Revisit when:** three months after 0.1.0, with the dashboard's issues and the npm advisories it needed.

## Consequences

- Good: a modern dashboard with real charts, components we own, nothing for users to install, and a strict CSP.
- Bad: a Node toolchain for contributors, npm advisories to follow (though only `dist/` ships), a larger download (a few hundred KB), and a freshness check in CI.

## Verification

- Pest: the page shell has the strict CSP, no inline script, the hashed assets and the nonce; the asset route serves `dist/` with long cache headers; every page route is behind the gates.
- Vitest with Testing Library for each page's components, against a mocked API.
- One end-to-end smoke test in a real browser (Playwright): create a rule → send requests → see the block in Activity.
- CI: `npm ci && npm run build` reproduces the committed `dist/` exactly; the bundle stays within budget; `npm audit` reports no high or critical advisory in the shipped dependencies; the Composer download holds `dist/` and no sources.

## Effect on other ADRs

Accepted ADRs are not rewritten. Read these lines as follows:

| ADR | Text | Read as |
|---|---|---|
| ADR-0018, point 2 | Blade views plus one hand-written JavaScript file, no npm, no build step | React + shadcn/ui, built with Vite into a committed `dist/` (this ADR) |
| ADR-0018, point 6 | System fonts, small stylesheet, server-rendered SVG charts | Tailwind and shadcn/ui, Recharts charts, light and dark themes |
| ADR-0018, verification | No `package.json`; CI enforces it | `package.json` is allowed; CI checks the build is fresh and within budget instead |
| ADR-0017, release | No `dist/`; the release fails when a `package.json` exists | The release fails when `dist/` is not what the sources build |
| ADR-0016, test layers | Pest feature tests for pages; `node --test` for helpers | Pest for the shell and routes, Vitest for components, one Playwright smoke test |

## Decision record

Decided by the maintainer on 2026-10-15, when Phase 5 started. With it, they chose: light and dark themes that follow the system, with a toggle; polling every 5 seconds, paused while the tab is hidden; times in the browser's time zone, with UTC on hover; ready-made rule templates; raw IPs and user ids in the live feed visible to viewers, as the audit log is; emergency controls on the Overview and a banner on every page while one is on; and a Settings page with the health checks and the effective configuration, read-only. Bulk actions, CSV export and importing or exporting rules wait for a later minor release.
