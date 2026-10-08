# Why a request is limited

`dynamic-throttle:explain` shows what the middleware does with a request, and why, without counting it ([ADR-0008](../adr/0008-rule-matching.md)):

```bash
php artisan dynamic-throttle:explain GET /api/orders --ip=203.0.113.7 --user=42 --header="X-Api-Key: abc"
```

- **The rules** the request matches, in the order they run, each with its limit under the scale factor. For each, who the client counts as: the identity as given, such as `ip:203.0.113.7`, and the hash its counter and `RateLimitWouldBlock` use. A rule that does not count the request says why: an exemption, no identity, the cap, a scope that failed, or an earlier fail-closed rule.
- **The counters**, on the Redis and cache drivers: the count in the sliding window, what remains now, and whether the next request goes through, with its `Retry-After`. That request's `X-RateLimit-Remaining` is one less than what remains, since it counts too. A custom `LimiterStore` shows the rules without counters.
- **The decision** the next request gets, with its headers: allowed, the 429 of the rule that rejects it, or the 503 of a fail-closed rule. Or why it goes through untouched: the route does not run `dynamic.throttle`, `enabled` is off, `except` lists the path, or bypass is on.
- **The route's middleware**, in running order, and whether `dynamic.throttle` runs after authentication, so you can see whether user rules see the user.
- **Inputs.** `--ip` is the client's IP as your app sees it, after its trusted proxies, and 127.0.0.1 by default. `--user` takes a user's id, which the user provider of `--guard`, or of the default guard, looks up. On a route where no authentication runs before `dynamic.throttle`, user rules count a guest, as they do for the request. `--header`, repeatable, feeds the `header` and `api_token` scopes and your custom resolvers, which receive the simulated request.
- **For scripts.** `--json` prints the same explanation as one JSON object, and an error as `{"error": "…"}`.
- **It changes nothing.** It reads the counters and the manifest, and never counts, writes or compiles. It decides as a server out of its cooldown does, and other requests can count between it and the next one.
