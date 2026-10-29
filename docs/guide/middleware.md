# Attaching the middleware

Rules apply only to routes that run the `dynamic.throttle` middleware, and installing the package attaches it nowhere ([ADR-0020](../adr/0020-laravel-ecosystem-coexistence.md)). Add it to your route groups in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('web', 'dynamic.throttle'); // pages and forms, such as login and sign-up
    $middleware->appendToGroup('api', 'dynamic.throttle'); // routes/api.php
})
```

Or list the groups in the `middleware.auto_attach` config key, such as `['web', 'api']`. **A route outside those groups is never limited.** Login, sign-up and password reset routes, such as Breeze's and Fortify's, are in `web`. A new Laravel app has no API routes until you run `php artisan install:api`.

- **After authentication.** The middleware runs after Laravel's authentication middleware, `auth` and `auth.basic`, even in a group that lists it first, so user rules see the user. If users log in through middleware that Laravel cannot recognize, such as a JWT package's or your own API key check, list it in `middleware.after`, by alias or class. Until you do, user rules count those users as guests.
- **Excluded paths.** No rule ever applies to the paths in the `except` config key, not even a `*` rule. By default, they are Horizon, Telescope, Pulse, Nova, Livewire 3 and 4, `broadcasting/auth`, `sanctum/csrf-cookie` and `up`. Remove an entry to limit its path.
- **Responses.** A limited request gets Laravel's own 429 (`ThrottleRequestsException`), rendered by your exception handler, with `Retry-After`, `X-RateLimit-Reset`, `X-RateLimit-Limit` and `X-RateLimit-Remaining: 0`. A rule's `response_override` replaces the body with its JSON, sent as JSON to every client, browsers included. An allowed request gets `X-RateLimit-Limit` and `X-RateLimit-Remaining` from its most restrictive enforcing rule. Dry-run rules never set headers.
- **Laravel's `throttle` on the same route.** Both limit the request, and whichever runs last writes the `X-RateLimit-*` headers. Pick one per route.
- **Failures.** If the counters, the manifest, a config value or one of your scope resolvers fails, the request goes through, and the error is reported to your exception handler. A rule can answer 503 instead: see [When something fails](failures.md).
