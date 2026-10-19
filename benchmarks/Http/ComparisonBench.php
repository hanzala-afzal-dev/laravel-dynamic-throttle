<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Benchmarks\Http;

use Afzal\DynamicThrottle\Benchmarks\Services;
use Afzal\DynamicThrottle\DynamicThrottleServiceProvider;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application as Laravel;
use Illuminate\Http\Request;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Orchestra\Testbench\Foundation\Application;
use PhpBench\Attributes as Bench;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #101: what one request costs through the whole HTTP kernel with no
 * limiter, with Laravel's throttle middleware, through the cache or straight on
 * Redis, and with Dynamic Throttle, each counting on the local Redis. The differences between the
 * cases are what each limiter adds; the kernel's own time is the same in all.
 *
 * Dynamic Throttle runs with 1 rule that matches, and with MiddlewareBench's 100
 * rules, 3 of which match, in three runtimes: the manifest in memory, as on
 * Octane; PHP-FPM with APCu, whose processes start empty and share the manifest
 * in APCu (phpbench.json enables it in the CLI); and PHP-FPM without APCu, which
 * reads the manifest on every request. Telemetry is on, as by default: its write
 * runs in terminate(), after the response, which this times apart.
 *
 * Each iteration is one request, in a process of its own, after 20 that warm it
 * up, so benchmarks/percentiles.php gives each case's p50 and p95. Every limit is
 * high enough that no request is ever rejected. php benchmarks/round-trips.php
 * counts each case's Redis calls and database queries.
 */
#[Bench\Revs(1)]
#[Bench\Iterations(200)]
#[Bench\Warmup(20)]
#[Bench\BeforeMethods('setUp')]
#[Bench\AfterMethods('tearDown')]
#[Bench\ParamProviders(['cases'])]
final class ComparisonBench
{
    public const string PATH = 'api/users/42/export';

    private const int LIMIT = 1_000_000;

    private Laravel $app;

    private Kernel $kernel;

    /** PHP-FPM starts each request with nothing in the process's memory. */
    private bool $emptyMemory = false;

    /**
     * @return iterable<string, array{limiter: string, store: string, rules: int, refresh: int, empty_memory: bool}>
     */
    public function cases(): iterable
    {
        $runtimes = [
            'manifest in memory (Octane)' => ['refresh' => 3_600, 'empty_memory' => false],
            'PHP-FPM with APCu' => ['refresh' => 2, 'empty_memory' => true],
            'PHP-FPM without APCu' => ['refresh' => 0, 'empty_memory' => true],
        ];

        yield 'no limiter' => ['limiter' => 'none', 'store' => 'redis', 'rules' => 0, 'refresh' => 3_600, 'empty_memory' => false];
        yield "Laravel's throttle" => ['limiter' => 'laravel', 'store' => 'redis', 'rules' => 0, 'refresh' => 3_600, 'empty_memory' => false];
        // What $middleware->throttleWithRedis() switches the throttle alias to.
        yield "Laravel's throttle, with throttleWithRedis()" => ['limiter' => 'laravel-redis', 'store' => 'redis', 'rules' => 0, 'refresh' => 3_600, 'empty_memory' => false];

        foreach ([1, 100] as $rules) {
            foreach ($runtimes as $runtime => $settings) {
                yield "Dynamic Throttle, {$rules} ".($rules === 1 ? 'rule' : 'rules').", {$runtime}" => ['limiter' => 'dynamic', 'store' => 'redis', 'rules' => $rules, ...$settings];
            }
        }
    }

    /**
     * @param  array{limiter: string, store: string, rules: int, refresh: int, empty_memory: bool}  $params
     */
    public function setUp(array $params): void
    {
        $this->app = Application::create(options: ['extra' => [
            'providers' => [DynamicThrottleServiceProvider::class],
            'dont-discover' => ['*'],
        ]]);

        $config = $this->app->make('config');
        $config->set('app.key', 'base64:3u4DMqUd5sEWtS4w9UqJ7Qw/qMCRnGFhqRuSN40wNJA=');
        $config->set('database.redis.bench', Services::redisConnection());
        // throttleWithRedis() counts on the default connection.
        $config->set('database.redis.default', Services::redisConnection());
        $config->set('cache.stores.bench', $params['store'] === 'redis'
            ? ['driver' => 'redis', 'connection' => 'bench', 'prefix' => Services::prefix()]
            : ['driver' => 'array', 'serialize' => false]);
        // Laravel's throttle counts in the default store, and Dynamic Throttle in its own.
        $config->set('cache.default', 'bench');
        $config->set('dynamic-throttle.store', 'bench');
        $config->set('dynamic-throttle.prefix', Services::prefix());
        $config->set('dynamic-throttle.manifest.refresh_seconds', $params['refresh']);
        // Laravel's limiter was built at boot, on the default store then.
        $this->app->forgetInstance(RateLimiter::class);
        $this->emptyMemory = $params['empty_memory'];
        ManifestRepository::flushState();

        $route = $this->app->make('router')->get(self::PATH, fn (): string => 'ok');

        if ($params['limiter'] === 'laravel') {
            $route->middleware(ThrottleRequests::class.':'.self::LIMIT.',1');
        }

        if ($params['limiter'] === 'laravel-redis') {
            $route->middleware(ThrottleRequestsWithRedis::class.':'.self::LIMIT.',1');
        }

        if ($params['limiter'] === 'dynamic') {
            $route->middleware('dynamic.throttle');
            $this->app->make('cache')->store('bench')->forever(
                $this->app->make(KeyBuilder::class)->manifest(),
                ManifestCodec::encode(1, $this->rules($params['rules'])),
            );
        }

        $this->kernel = $this->app->make(Kernel::class);

        // A failure lets the request through without headers, which would time the failure.
        $response = $this->send();

        if ($response->getStatusCode() !== 200 || $response->headers->get('X-RateLimit-Limit') !== ($params['limiter'] === 'none' ? null : (string) self::LIMIT)) {
            throw new RuntimeException("The benchmark request was not counted as expected: {$response->getStatusCode()}.");
        }
    }

    public function tearDown(): void
    {
        $this->app->make('cache')->store('bench')->forget($this->app->make(KeyBuilder::class)->manifest());
        ManifestRepository::flushState();
    }

    public function benchRequest(): void
    {
        $this->send(terminate: false);
    }

    public function benchRequestAndTerminate(): void
    {
        $this->send();
    }

    /**
     * The Redis calls and database queries of one request, before its response and
     * after, in terminate(), for benchmarks/round-trips.php.
     *
     * @return array{redis: int, redis_after: int, queries: int}
     */
    public function count(): array
    {
        $this->app->make('redis')->purge('bench');
        $this->app->make('redis')->purge('default');
        $this->app->make('redis')->enableEvents();
        $redis = 0;
        $queries = 0;
        $this->app->make('events')->listen(CommandExecuted::class, function () use (&$redis): void {
            $redis++;
        });
        $this->app->make('events')->listen(QueryExecuted::class, function () use (&$queries): void {
            $queries++;
        });

        $request = $this->request();
        $this->before();
        $response = $this->kernel->handle($request);
        $beforeResponse = $redis;
        $this->kernel->terminate($request, $response);

        return ['redis' => $beforeResponse, 'redis_after' => $redis - $beforeResponse, 'queries' => $queries];
    }

    private function send(bool $terminate = true): Response
    {
        $request = $this->request();
        $this->before();
        $response = $this->kernel->handle($request);

        if ($terminate) {
            $this->kernel->terminate($request, $response);
        }

        return $response;
    }

    /**
     * What PHP-FPM starts each request with, and what Laravel builds for each request,
     * on Octane as on PHP-FPM: the scoped services.
     */
    private function before(): void
    {
        if ($this->emptyMemory) {
            ManifestRepository::flushState();
        }

        $this->app->forgetScopedInstances();
    }

    private function request(): Request
    {
        return Request::create('/'.self::PATH, 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);
    }

    /**
     * One rule that matches the request, or MiddlewareBench's 100: three that match it,
     * four more that it tests without a match, and other services' rules that the index
     * never offers.
     *
     * @return list<CompiledRule>
     */
    private function rules(int $count): array
    {
        $rules = $count === 1 ? [['GET', 'api/users/*']] : [
            ['*', 'api/*'],
            ['GET', 'api/users/*'],
            ['*', '*/export'],
            ['GET', 'api/orders/*'],
            ['*', 'api/users'],
            ['*', '*/admin/*'],
            ['GET', '*.php'],
            ['POST', 'api/users/*'],
        ];

        for ($id = count($rules) + 1; $id <= $count; $id++) {
            $rules[] = [['*', 'GET', 'POST'][$id % 3], "service-{$id}/*"];
        }

        return array_map(
            fn (array $rule, int $position): CompiledRule => CompiledRule::fromArray(ManifestFixtures::rule([
                'id' => (string) ($position + 1),
                'method' => $rule[0],
                'pattern' => $rule[1],
                'max_attempts' => self::LIMIT,
            ])),
            $rules,
            array_keys($rules),
        );
    }
}
