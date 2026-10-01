<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Benchmarks\Http;

use Afzal\DynamicThrottle\Benchmarks\Services;
use Afzal\DynamicThrottle\DynamicThrottleServiceProvider;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Matching\RuleMatcher;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Application as Laravel;
use Illuminate\Http\Request;
use Orchestra\Testbench\Foundation\Application;
use PhpBench\Attributes as Bench;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * SPEC-010 AC-20, the Phase 2 exit benchmark: what the middleware adds to one
 * request, with 100 active rules, 3 of which match it, and every one allows it.
 *
 * Each iteration is one request, in a process of its own, after 20 requests
 * that warm it up. So the iterations are single requests, and their
 * distribution gives the p95 (benchmarks/percentiles.php).
 *
 * phpbench.json enables APCu in the CLI, for SPEC-028's case. Each process
 * has an APCu of its own, as each PHP-FPM server has.
 */
#[Bench\Revs(1)]
#[Bench\Iterations(200)]
#[Bench\Warmup(20)]
#[Bench\BeforeMethods('setUp')]
#[Bench\AfterMethods('tearDown')]
#[Bench\ParamProviders(['stores', 'manifests'])]
final class MiddlewareBench
{
    private const string PATH = 'api/users/42/export';

    /** High enough that no request of a run is ever rejected. */
    private const int LIMIT = 1_000_000;

    private Laravel $app;

    private Request $request;

    private Response $response;

    /** PHP-FPM starts each request with nothing in the process's memory. */
    private bool $emptyMemory = false;

    /**
     * @return iterable<string, array{store: string}>
     */
    public function stores(): iterable
    {
        yield 'array store' => ['store' => 'array'];
        yield 'local Redis' => ['store' => 'redis'];
    }

    /**
     * @return iterable<string, array{refresh: int, empty_memory: bool}>
     */
    public function manifests(): iterable
    {
        // Octane and queue workers keep the manifest in memory between requests.
        yield 'manifest in memory' => ['refresh' => 3_600, 'empty_memory' => false];
        // PHP-FPM without APCu reads and decodes the manifest on every request.
        yield 'manifest read per request' => ['refresh' => 0, 'empty_memory' => false];
        // PHP-FPM with APCu builds it from what the server's workers keep there (SPEC-028 AC-12).
        yield 'PHP-FPM with APCu' => ['refresh' => 2, 'empty_memory' => true];
    }

    /**
     * @param  array{store: string, refresh: int, empty_memory: bool}  $params
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
        $config->set('cache.stores.bench', $params['store'] === 'redis'
            ? ['driver' => 'redis', 'connection' => 'bench']
            : ['driver' => 'array', 'serialize' => false]);
        $config->set('dynamic-throttle.store', 'bench');
        $config->set('dynamic-throttle.prefix', Services::prefix());
        $config->set('dynamic-throttle.manifest.refresh_seconds', $params['refresh']);
        $this->emptyMemory = $params['empty_memory'];
        ManifestRepository::flushState();

        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }

        $rules = $this->rules();

        if (count((new RuleMatcher)->match(new Manifest(1, $rules), 'GET', self::PATH)->rules) !== 3) {
            throw new RuntimeException('The benchmark request must match exactly 3 rules.');
        }

        $this->cache()->forever($this->app->make(KeyBuilder::class)->manifest(), ManifestCodec::encode(1, $rules));
        $this->request = Request::create('/'.self::PATH, 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $this->response = new Response('ok');

        // A store that fails lets the request through without headers, which would time the failure.
        $this->benchRequest();

        if ($this->response->headers->get('X-RateLimit-Limit') !== (string) self::LIMIT) {
            throw new RuntimeException('The rules did not count the benchmark request.');
        }
    }

    public function tearDown(): void
    {
        $this->cache()->forget($this->app->make(KeyBuilder::class)->manifest());
        ManifestRepository::flushState();
    }

    public function benchRequest(): void
    {
        if ($this->emptyMemory) {
            ManifestRepository::flushState();
        }

        // What Laravel builds for each request, on Octane as on PHP-FPM: the scoped
        // services, then the middleware.
        $this->app->forgetScopedInstances();
        $this->app->make(DynamicThrottleMiddleware::class)->handle($this->request, fn (): Response => $this->response);
    }

    private function cache(): Repository
    {
        return $this->app->make('cache')->store('bench');
    }

    /**
     * RuleMatcherBench's rules: three that match the request, four more that it
     * tests without a match, and other services' rules that the index never offers.
     *
     * @return list<CompiledRule>
     */
    private function rules(): array
    {
        $rules = [
            ['*', 'api/*'],
            ['GET', 'api/users/*'],
            ['*', '*/export'],
            ['GET', 'api/orders/*'],
            ['*', 'api/users'],
            ['*', '*/admin/*'],
            ['GET', '*.php'],
            ['POST', 'api/users/*'],
        ];

        for ($id = count($rules) + 1; $id <= 100; $id++) {
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
