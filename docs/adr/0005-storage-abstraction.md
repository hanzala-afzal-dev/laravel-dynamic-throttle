# ADR-0005: Storage abstraction and drivers

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 1
- Amended by: [ADR-0026](0026-one-round-trip-per-request.md) (one call counts all of a request's attempts)
- Related: ADR-0006, ADR-0007, ADR-0010

## Context

- Not every Laravel app runs Redis. New Laravel apps default to the database cache store.
- Cache stores give very different guarantees:
  - Some are shared across servers (Redis, Memcached, DynamoDB, database).
  - Some are local to one server (file, APCu).
  - Some are local to one request (array).

## Decision

1. **One contract for counters:**

   ```php
   interface LimiterStore
   {
       /** Atomically check and count one request for a rule + identity. */
       public function attempt(CounterKey $key, int $maxAttempts, int $windowSeconds): Attempt;

       /** Undo a counted request when a later rule rejects it (ADR-0008). */
       public function release(Attempt $attempt): void;
   }
   ```

2. **Two drivers:**
   - **`redis`:**
     - Uses a Lua script and one Redis hash per counter.
     - Takes time from Redis `TIME` (ADR-0006).
     - Calls `EVALSHA` and falls back to `EVAL` on a `NOSCRIPT` error.
     - Goes through Laravel's Redis connection, so both phpredis and predis work.
   - **`cache`:**
     - Works with any Laravel cache store through the cache repository, using `add`, `increment`, `decrement` and `get`.
     - Keeps two keys per counter: current window and previous window.
3. **Driver selection:** config `driver` is `auto`, `redis` or `cache`.
   - `auto` picks `redis` when the configured cache store uses the Redis driver, otherwise `cache` on that store.
   - Selection reads config only; no network calls at boot.
4. **Warnings, not exceptions.** The health check reports:
   - file/APCu store: "counts per server; limits multiply by server count"
   - database store: "adds database writes to every limited request"
   - array store: "for tests only"
5. **Key prefix** is configurable. Apps sharing one Redis must use different prefixes.
6. The compiled rule manifest uses the same store (ADR-0007).

## Reasoning

- The interface lets the core engine be tested against the array store and swapped per environment.
- Laravel itself works this way: `ThrottleRequests` works with any store, while `ThrottleRequestsWithRedis` uses Redis-specific tricks.
- `add` and `increment` are atomic on Redis and Memcached. The database store is correct but slow. File and APCu stores are not shared across servers.
- One hash key per counter matters for Redis Cluster, where every key a script touches must live in the same slot. A single key avoids that problem entirely.
- `EVALSHA` avoids sending the script body on every request. The `NOSCRIPT` fallback covers restarts and failovers that flush the script cache.

## Alternatives considered

- **Redis only.** Simplest, but shuts out many apps. Rejected.
- **A dedicated driver per store** (Memcached, DynamoDB…). More code for little gain; the generic driver covers them. Deferred until a store needs special handling.
- **Reusing Laravel's `RateLimiter` class for the cache driver.** Fixed window only; no clean way to do the sliding counter or compensation. Rejected.

## Fallback

- If the Lua script fails on some Redis-compatible server (a missing command, managed-service limits), the `cache` driver still works on a Redis cache store because it only needs `INCR` and `SET NX`.
- If the generic driver misbehaves on a specific store, mark that store "not supported" in the health check and recommend Redis.
- **Revisit when:** a store needs special handling (for example, DynamoDB latency) → add a dedicated driver.

## Consequences

- Good: works out of the box on any Laravel app.
- Bad: guarantees differ per driver. The docs need a per-driver table, and the contract tests must run against each store.

## Verification

- A shared contract test suite (ADR-0016) runs against Redis (phpredis and predis), Valkey, Memcached, the database store and the array store.
- Health-check tests cover each warning.
