# SPEC-030: The live feed of blocks

- Status: Approved
- ADRs: ADR-0013, ADR-0024 (amended here), ADR-0026, ADR-0028
- Roadmap phase: 5

## Problem

During an incident, an admin needs to see who is being blocked right now: which client, on which route, by which rule. The dashboard has only counts and identity hashes (ADR-0024). A hash cannot be read, and so it cannot be acted on, for example to exempt a partner's IP or to block an attacker upstream.

The maintainer decided at the Phase 3 review that the dashboard keeps the latest blocks and would-blocks for an hour, with the client's raw IP and user id. Viewers can see them, as they can see the audit log (ADR-0028). This spec defines what is kept, where, for how long, and what never is. Activity (SPEC-024) shows it.

## Behavior

### What the feed keeps
- **Each block and would-block** of a request the telemetry records leaves one entry:
  - `time`: when, in Unix milliseconds;
  - `rule`: the rule's id;
  - `outcome`: `blocked` or `would_block`;
  - `method`: the request's method;
  - `route`: the matched route's URI template, such as `orders/{order}`, or null without a route;
  - `ip`: the client IP as the app reads it after its trusted proxies, IPv6 whole, or null when it cannot be read;
  - `user`: the user the request already holds, as `Class:id`, or null;
  - `identity`: the identity hash the rule counted, which links an entry to the rule's offenders.
- **Never kept** (decision 3):
  - the request's path or query string, which can carry tokens, such as a password reset link;
  - an API token, a header's value, or a custom scope's value;
  - anything about an allowed request.
- **The user is never looked up for the feed.** It is the user a user rule or exemption already resolved, or the one the default guard already holds. Otherwise it is null, so the feed adds no database query (CLAUDE.md's hard rule).

### Where and for how long
- **On Redis and Valkey only,** in the counter store, beside the telemetry (decision 1).
  - One list per minute, newest first, cut to 500 entries.
  - Each list expires 61 minutes after it is created, so no entry outlives an hour and a minute.
- **In the request's telemetry call.** The entries go in the script call that records the request's telemetry, so the feed adds no round trip (ADR-0026). It is sampled with the telemetry, and skipped like it while the counter store's cooldown runs.
- **A generic cache store keeps no feed,** as it keeps no offenders.
- **`telemetry.live_feed`, true by default,** turns the feed off: for an app that must not keep raw IPs, even for an hour (decision 5).
  - It is also off whenever telemetry is.

### `GET api/feed`
- Either gate, like every read (SPEC-018), named `dynamic-throttle.api.feed`. Viewers see the raw IPs and users (ADR-0028).
- Answers the last 60 minutes' newest 500 entries, newest first, each with its rule's name, which is null for a rule deleted since:
  ```json
  {"feed": [{"time": 1800000000123, "rule": 3, "rule_name": "Login", "outcome": "blocked", "method": "POST", "route": "login", "ip": "203.0.113.7", "user": null, "identity": "9f2c…"}]}
  ```
- `?since={time}` answers only the entries after that time, so a page that polls fetches only what is new.
- On a store without a feed, it answers `{"feed": null}`. With the feed off, it answers `{"feed": []}`.
- A failing counter store answers 503 with the API's message (SPEC-017).

## Acceptance criteria

### Engine and middleware (Pest, the driver suite on Redis and Valkey)
- **AC-1** A request an enforcing rule blocks, on Redis, leaves an entry with its time, the rule, `blocked`, the method, the route's template, the client IP, and the identity hash. A dry-run rule's would-block leaves one with `would_block`. An allowed request leaves none.
- **AC-2** Behind an `api_token` rule, a `header` rule and a custom-scope rule, no entry holds the token, the header's value or the scope's value. No entry holds the request's path with its values, or its query string.
- **AC-3** The user is the one a user rule resolved, as `Class:id`, or the one the default guard already holds. With neither, it is null, and the feed made no database query.
- **AC-4** A minute's list holds the newest 500 entries, newest first, and expires 61 minutes after it is created, however often it is written. A read gives the last 60 minutes' newest 500, newest first, and with `since`, only the entries after it.
- **AC-5** The entries go in the request's one telemetry call. A request the sampler skips leaves none, and so does one during the cooldown. With telemetry off, or `telemetry.live_feed` off, nothing is written.
- **AC-6** On a Redis Cluster, each list is written in its own call, as the telemetry's keys are. The cache buffer keeps no feed and reads none.
- **AC-7** An IPv6 client's entry holds the whole address, not its /64.

### API (Pest)
- **AC-8** `GET api/feed` answers a viewer the entries, newest first, with their rules' names, null for a deleted rule. `since` keeps only the newer ones, and a `since` that is not a number answers 422. On the cache driver it answers `feed: null`. A guest in production gets 403.

### Checks
- **AC-9 (benchmark)** The middleware benchmark's allowed request costs the same as before, and a blocked request's telemetry call is measured with its feed entry, recorded in `benchmarks/results/`.
- **AC-10 (mutation)** The changed engine files keep a mutation score of at least 85% (ADR-0016).

## Interfaces and files

- `src/Engine/KeyBuilder.php`: `feed(int $minute)`, under the telemetry's `t:` prefix.
- `src/Engine/Telemetry/FeedEntry.php`: the entry, a readonly value object.
- `src/Engine/Contracts/TelemetryBuffer.php`: `record()` takes the request's feed entries, and `feed(int $minute, ?int $since): ?array` reads them, null for a buffer that keeps none.
- `src/Engine/Telemetry/RedisTelemetryBuffer.php`: a list kind in the record script, and the read.
- `src/Engine/Telemetry/CacheTelemetryBuffer.php`: no feed.
- `src/Http/Middleware/DynamicThrottleMiddleware.php`: builds the entries from what handle() kept.
- `src/Http/RequestIdentityResolver.php`: the raw client IP and the held user, never a lookup.
- `src/Dashboard/Api/FeedController.php` and its route.
- `config/dynamic-throttle.php`: `telemetry.live_feed`.
- `docs/adr/0024-keyed-identity-hashing.md`: the amendment.

## Out of scope

- The Activity page that shows the feed (SPEC-024).
- Keeping the feed in the database, or longer than an hour.
- Exempting or blocking a client from an entry: Activity may link to the Exemptions page.

## Edge cases and failure modes

- **An attack blocks thousands of requests a second:** each minute's list stays at 500. The work per blocked request is one more list push in a call the telemetry already makes, and sampling bounds it further.
- **The counter store fails:** the telemetry call fails as before, reported once per cause and cooldown. The response is never affected (ADR-0010).
- **A rule is deleted:** its entries stay until they expire, with `rule_name` null.
- **Clocks differ between servers:** each entry is filed under the minute of the server that wrote it. A read of the last 60 minutes reads by the reading server's clock, so a few seconds of skew can hide or show the oldest minute's entries.
- **The app's trusted proxies are wrong:** every entry shows the proxy's IP, as the `dominant-ip` health check warns (SPEC-016).

## End-to-end verification

- `composer test`, `composer analyse`, `composer test:drivers`
- `composer test:mutate` on the changed engine files
- `composer bench` for the middleware, compared with the last recorded results
- In the Workbench app: run `demo:traffic`, then `curl {path}/api/feed` and see the blocks with their IPs.

## Decisions

The maintainer vetoes any of these in the PR (ADR-0027).

1. **Per-minute lists on Redis, each expiring 61 minutes after it is created.** An entry never outlives an hour and a minute, with no sweep to run. A single list whose TTL each write pushed back would keep old entries for as long as blocks keep coming.
2. **In the telemetry's own call, sampled with it.** The feed costs no round trip. At `sample_rate` below 1 it shows a sample, which Activity says.
3. **The route's template, never the path.** A path can carry a reset token or a signed URL, and the template still tells which endpoint was hit. Tokens, header values and custom scope values never leave their hash, so only IPs and users, which an admin can act on, are raw.
4. **The user is never looked up for the feed.** It is the user the request already holds, so the feed adds no database query on the request path.
5. **`telemetry.live_feed`, true by default,** as the maintainer chose to show raw IPs, with an off switch for apps that must not keep them, even for an hour. It is a new config key, so the maintainer may rename it before 0.1.0.
6. **This amends ADR-0024** in its own file, with a dated section: identities stay hashed everywhere else, and raw IPs and users exist only in the feed, for an hour.

## PRs

| PR | Branch | Adds | Covers |
|---|---|---|---|
| 1 | `phase-5/spec-030-1` | This spec, and ADR-0024's amendment | Decisions |
| 2 | `phase-5/spec-030-2` | The feed in the buffers, its keys and its read | AC-4, AC-6, AC-10 |
| 3 | `phase-5/spec-030-3` | The middleware's entries, `telemetry.live_feed`, `GET api/feed`, the benchmark, the guide, the changelog and the spec's close | AC-1 to AC-3, AC-5, AC-7 to AC-9 |

## Open questions

None.
