# Attaching the middleware

Rules apply only to routes that run the `dynamic.throttle` middleware, and installing the package attaches it nowhere. Add it to your route groups in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('web', 'dynamic.throttle'); // pages and forms, such as login and sign-up
    $middleware->appendToGroup('api', 'dynamic.throttle'); // routes/api.php
})
```

Or list the groups in the `middleware.auto_attach` config key, such as `['web', 'api']`. **A route outside those groups is never limited**, and `php artisan dynamic-throttle:health` names each rule that matches only such routes (the `unreached` check). Login, sign-up and password reset routes, such as Breeze's and Fortify's, are in `web`. A new Laravel app has no API routes until you run `php artisan install:api`.

- **After authentication.** The middleware runs after Laravel's authentication middleware, `auth` and `auth.basic`, even in a group that lists it first, so user rules see the user. If users log in through middleware that Laravel cannot recognize, such as a JWT package's or your own API key check, list it in `middleware.after`, by alias or class. Until you do, user rules count those users as guests.
- **Excluded paths.** No rule ever applies to the paths in the `except` config key, not even a `*` rule. By default, they are Horizon, Telescope, Pulse, Nova, Livewire 3 and 4, `broadcasting/auth`, `sanctum/csrf-cookie` and `up`. Remove an entry to limit its path.
- **Responses.** A limited request gets Laravel's own 429 (`ThrottleRequestsException`), rendered by your exception handler, with `Retry-After`, `X-RateLimit-Reset`, `X-RateLimit-Limit` and `X-RateLimit-Remaining: 0`. A rule's `response_override` replaces the body with its JSON, sent as JSON to every client, browsers included. An allowed request gets `X-RateLimit-Limit` and `X-RateLimit-Remaining` from its most restrictive enforcing rule. Dry-run rules never set headers.
- **Laravel's `throttle` on the same route.** Both limit the request, and whichever runs last writes the `X-RateLimit-*` headers. Pick one per route.
- **Failures.** If the counters, the manifest, a config value or one of your scope resolvers fails, the request goes through, and the error is reported to your exception handler. A rule can answer 503 instead: see [When something fails](failures.md).

## Behind a load balancer or a CDN

Behind a load balancer, a CDN such as Cloudflare, or any other proxy, every request reaches your app from the proxy's address. Until you tell Laravel to trust the proxy, `$request->ip()` gives that address for every client. Then an `ip` rule counts all your clients on one counter, and so does the IP fallback for guests: a Login rule of 5 a minute blocks everyone after the fifth login.

Trust your proxies in `bootstrap/app.php`, in the same `withMiddleware()` call:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: ['10.0.0.0/8']); // your proxies' addresses
})
```

- **List only your proxies.** `at: '*'` trusts every address, so use it only when clients cannot reach your app except through the proxy: otherwise a client can choose its own IP with an `X-Forwarded-For` header. Laravel's docs show the other options, such as the headers to read: [trusted proxies](https://laravel.com/docs/requests#configuring-trusted-proxies).
- **To check it,** look at the dashboard's Activity page once rules block: if every row shows the same IP, your proxy's, the proxies are not trusted yet. On Redis, `php artisan dynamic-throttle:health` also warns about it once enough requests were recorded (the `dominant-ip` check).
