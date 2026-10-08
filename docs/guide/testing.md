# Testing your app

Call `DynamicThrottle::fake()` in every test that sends requests through the middleware, for example in your base test case's `setUp()`. The counters, the manifest and the telemetry then live in memory: tests need no Redis, and each one starts from zero. Save rules with the `Rule` model, before or after `fake()`, and assert on the response:

```php
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Models\Rule;

DynamicThrottle::fake();

Rule::create(['name' => 'Login', 'pattern' => 'login', 'scope' => 'ip', 'max_attempts' => 5, 'window_seconds' => 60]);

$this->post('/login')->assertHeader('X-RateLimit-Remaining', 4);
```

The counters read Laravel's clock, so `$this->travel()` moves their windows. The fake store never fails, and each `fake()` starts without a cooldown.
