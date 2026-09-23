# ADR-0001: Scope and positioning

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 0

## Context

Laravel's built-in rate limiting (`RateLimiter::for()`, `throttle` middleware) lives in code. Changing a limit means a code change and a deploy, and Laravel has no built-in screen showing who is being limited.

Existing packages (checked September 2026):

- `satheez/laravel-rate-limit-dashboard`: first release May 2026, very few installs. Monitoring, runtime limiter settings, per-user and per-IP overrides. Stores everything in the SQL database.
- `akaunting/laravel-firewall`: popular (~980 stars), but a web application firewall. Settings live in a config file. Blocks IPs after failed logins.
- `jdarkins/pulse-throttled`: a read-only Laravel Pulse card for 429 responses.

Edge tools (Cloudflare, Nginx, API gateways) limit by IP and path. They cannot see application identity: the user, the owner of an API token, the customer's plan.

## Decision

Build an **application-level rate-limit control plane** for Laravel:

1. Rules are data. They are stored in the database, changed at runtime and applied without a deploy or route changes.
2. Rules are identity-aware: IP, user, API token, trusted header, global, and custom resolvers the host app registers.
3. Dry-run is a first-class feature: test a limit on real traffic without blocking anyone.
4. The package ships a dashboard, a JSON API for it, and Artisan commands.

**Non-goals for v1:** WAF features (SQLi/XSS detection), bot detection, geo-blocking, replacing edge rate limiting, billing quotas or usage metering, limiting queue jobs or outgoing HTTP calls, and central control of several apps from one dashboard.

## Reasoning

- Identity-aware limits are the gap edge tools cannot fill. That is our niche.
- Dry-run is the clearest difference from every existing package. It answers the question every operator has before tightening a limit: "who would this block?"
- A narrow scope keeps the request path small and fast, and keeps maintenance realistic for a solo maintainer.

## Alternatives considered

- **Monitoring-only package.** Already exists (pulse-throttled, satheez). Low added value.
- **Database-backed named limiters for Laravel's `RateLimiter`.** Still needs a route change per limiter, so it loses "rules without touching routes". Could be added later as an integration.
- **All-in-one security suite (WAF + rate limiting).** Large surface, competes with akaunting and edge tools, and dilutes the pitch.

## Fallback

- If early users mostly ask for visibility, the telemetry and dashboard already cover that; lead with them in the README.
- If adoption stays low, the project still has value as a well-built reference implementation. Keep the dependency set small so maintenance stays cheap.
- **Revisit when:** three months after the first public release (0.1), review issues, discussions and install numbers.

## Consequences

- Good: a one-sentence pitch ("change rate limits at runtime, per user or token, and dry-run them first").
- Bad: the README must explain that edge rate limiting is still recommended for volumetric attacks.

## Verification

- The README's first screen states the niche and the non-goals.
- `docs/comparison.md` (vs. Laravel built-in, satheez, akaunting firewall, Pulse, edge tools) exists before 1.0.
