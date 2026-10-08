<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Benchmarks\Http;

use Afzal\DynamicThrottle\Benchmarks\Services;
use Afzal\DynamicThrottle\DynamicThrottleServiceProvider;
use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryOutcome;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryRecord;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Illuminate\Foundation\Application as Laravel;
use Illuminate\Http\Request;
use Orchestra\Testbench\Foundation\Application;
use PhpBench\Attributes as Bench;
use RuntimeException;

/**
 * SPEC-015 decision 12: what terminate() costs a request after its response, to
 * write its telemetry for MiddlewareBench's 3 matching rules. The response is
 * sent by then, so this is time the worker spends, not time the client waits.
 *
 * Each iteration is one request's write, in a process of its own, after 20 that
 * warm it up, so the distribution gives the p95 (benchmarks/percentiles.php).
 * As on a request, the scoped services are built again each time.
 */
#[Bench\Revs(1)]
#[Bench\Iterations(200)]
#[Bench\Warmup(20)]
#[Bench\BeforeMethods('setUp')]
#[Bench\ParamProviders(['stores', 'outcomes'])]
final class TelemetryBench
{
    /** The request attribute handle() leaves for terminate(), as the middleware names it. */
    private const string AFTER_RESPONSE = 'dynamic-throttle.after-response';

    /** A client's identity hash, as the counters key it. */
    private const string CLIENT = 'b5bb9d8014a0f9b1d61e21e796d78dccdf1352f23cd32812f4850b878ae4944c';

    private Laravel $app;

    private Request $request;

    /** @var list<TelemetryRecord> */
    private array $records = [];

    /**
     * @return iterable<string, array{store: string}>
     */
    public function stores(): iterable
    {
        yield 'array store' => ['store' => 'array'];
        // The cache driver over the network: an add() and an increment() per rule and metric.
        yield 'local Memcached' => ['store' => 'memcached'];
        // The Redis driver: one script call.
        yield 'local Redis' => ['store' => 'redis'];
    }

    /**
     * @return iterable<string, array{would_block: bool}>
     */
    public function outcomes(): iterable
    {
        // Most requests: each rule counts them.
        yield '3 rules count it' => ['would_block' => false];
        // A dry-run rule would block it too, which adds its offenders and clients.
        yield '1 of 3 would block it' => ['would_block' => true];
    }

    /**
     * @param  array{store: string, would_block: bool}  $params
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
        $config->set('cache.stores.bench', match ($params['store']) {
            'redis' => ['driver' => 'redis', 'connection' => 'bench'],
            'memcached' => ['driver' => 'memcached', 'servers' => Services::memcachedServers()],
            default => ['driver' => 'array', 'serialize' => false],
        });
        $config->set('dynamic-throttle.store', 'bench');
        $config->set('dynamic-throttle.prefix', Services::prefix());
        ManifestRepository::flushState();

        $this->records = [
            new TelemetryRecord('1', self::CLIENT, TelemetryOutcome::Counted),
            new TelemetryRecord('2', self::CLIENT, TelemetryOutcome::Counted),
            new TelemetryRecord('3', self::CLIENT, $params['would_block'] ? TelemetryOutcome::WouldBlock : TelemetryOutcome::Counted),
        ];
        $this->request = Request::create('/api/users/42/export', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);

        // A write that fails is reported and skipped, which would time the failure.
        $minute = intdiv($this->app->make(Clock::class)->microseconds(), 60_000_000);
        $this->benchTerminate();
        $buffer = $this->app->make(TelemetryBuffer::class);
        // The write may fall in the next minute.
        $hits = array_sum(array_map(fn (int $at): int => ($buffer->counts($at, ['1'])['1'] ?? null)?->hits ?? 0, [$minute, $minute + 1]));

        if ($hits !== 1) {
            throw new RuntimeException('terminate() did not record the benchmark request.');
        }
    }

    public function benchTerminate(): void
    {
        // What handle() leaves for terminate(): the records, and no event to dispatch.
        $this->request->attributes->set(self::AFTER_RESPONSE, ['events' => [], 'telemetry' => $this->records]);
        $this->app->forgetScopedInstances();
        $this->app->make(DynamicThrottleMiddleware::class)->terminate($this->request);
    }
}
