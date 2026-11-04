# ADR-0031: One-time links open the dashboard without a gate

- Status: Accepted
- Date: 2026-10-30
- Roadmap phase: after 0.1.0, in 0.2.0
- Amends: ADR-0015 (point 1: who gets in, and point 3: what the audit log records)
- Related: ADR-0007, ADR-0011, ADR-0017, ADR-0028

## Context

- ADR-0015 lets two gates decide who opens the dashboard. A gate checks a signed-in user, so outside the `local` environment, an app without a user login has nobody a gate can let in. Its only ways in today are a gate that accepts a guest, checked by IP or a header, or HTTP Basic auth in `dashboard.middleware`. Both are easy to get wrong, and the audit log then names every change `guest`.
- During an incident, an app with a login may need to show the dashboard to someone who has no account with the right gate, such as an on-call engineer from another team, for an hour.
- The maintainer asked for this on 2026-10-19 (the roadmap's "Dashboard access in production"). SPEC-033 shipped the first half: a health check that says when nobody can open the dashboard.

## Decision

1. **Links are a third way in, off by default.** With `dashboard.links` on, a link that `php artisan dynamic-throttle:link` prints opens the dashboard. Without it, nothing changes: the gates decide alone.
2. **Only the CLI makes links.** Whoever makes one has a shell on the server, the most trusted access an app has. A link's session cannot make another link, so a leaked link cannot extend itself.
3. **A link works once, for at most a day.** It lasts 60 minutes unless `--for` says otherwise. It lets its holder view, or with `--manage` also change rules, exemptions and settings, as `manageDynamicThrottle` does. It names who it is for (`--name`). `--revoke` ends one link early, and `--revoke-all` ends every link.
4. **The token never reaches a server log, a `Referer` header, the browser's history or the database.** The link carries 32 random bytes after a `#`, which browsers never send to a server. Its page removes them from the address bar at once and posts them only when its holder presses "Continue", so a chat app's link preview never uses the link. The database keeps only a SHA-256 hash of the token.
5. **Using a link starts a session, which ends when the link does.** The server checks the link on each of the dashboard's requests, so an expired or revoked link stops working at the next request.
6. **Links add to the gates.** A request gets what either allows. A change's actor is whoever allowed it: the signed-in user when a gate does, otherwise the link, `link:<id>:<name>`.
7. **The audit log records every link made, used or ended,** not only changes to rules, exemptions and settings. Its writer stays `EmergencyControls` (SPEC-013 AC-23).
8. **Links live in a table of their own, `dynamic_throttle_links`,** like the rules, not in the cache.

## Reasoning

- **Off by default:** an app that relies on its gates gets no new way in until it opts in.
- **One use, a day at most:** a link is a password in a chat message. It should be worth nothing once its holder has used it, and little if nobody does.
- **A fragment instead of a path or query:** web servers, proxies and analytics log paths and queries, and browsers send them in `Referer` headers. They never send the fragment.
- **A table instead of the cache:**
  - A link must survive `cache:clear`, which someone may run during the very incident the link is for.
  - It must survive a cache that evicts keys when its memory is full.
  - It must be seen by every server: the file and APCu stores keep a copy per server.
  - Listing links, and ending all of them, needs a query, which a cache cannot run without an index of its own that two commands at once could corrupt.
  - The dashboard is not on the request path, so reading the table there keeps the hard rule (ADR-0007).
- **Actor by who allowed the change:** the audit log answers "who could do this", so an entry names the gate's user, or the link when no gate allowed it.

## Alternatives considered

- **Laravel's signed URLs** (`URL::temporarySignedRoute`). They work until they expire, as often as anyone opens them, carry their signature in the query, which servers log, and cannot be revoked. Rejected.
- **A gate that accepts guests by IP, or HTTP Basic auth.** Possible today, and documented. They need code in the app, or a users table for Basic auth, and give the audit log no name. Kept as they are, not as the answer.
- **Links in the cache.** No migration, and expiry for free. Rejected for the reasons above.
- **The token in the link's path.** Simpler, with no script on the page, but every web server and proxy logs it. Rejected.
- **Links made from the dashboard.** Convenient for a manager, but a leaked `--manage` link could then make more links. Rejected for now.

## Fallback

- If one use is too strict, such as for a holder who switches browsers, allow a link to be used a few times, set when it is made.
- If the table's migration is too much to ask for an opt-in feature, move the links into the cache, with the risks above written down.
- **Revisit when:** users ask to make or end links from the dashboard, or for longer than a day.

## Consequences

- Good: an app without a user login can use the dashboard in production, and every change made there names its person.
- Good: an incident can bring in a helper for an hour, and the audit log says who made the link and what its holder changed.
- Bad: one more table, so turning links on means publishing and running one more migration.
- Bad: one more way into the control plane, so the code that checks links gets a security review.

## Verification

- SPEC-034's acceptance criteria, which test each point above.
- The arch tests: `EmergencyControls` stays the audit log's only writer, and the request path never uses the links' code.
- A security review by a Claude Code subagent of the code that makes, uses and checks links.

## Decision record

Proposed with SPEC-034 on 2026-10-30, and reviewed with it in its own pull request before any code, since it amends ADR-0015. Merging that pull request accepts it.
