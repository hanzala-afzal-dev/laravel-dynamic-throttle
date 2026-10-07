<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Telemetry\MinuteCounts;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Http\Untouched;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Support\ConfigValues;
use Afzal\DynamicThrottle\Support\LimiterStoreFactory;
use Afzal\DynamicThrottle\Support\RouteMiddleware;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Throwable;

/**
 * What several health checks read, read once for the report: the counter driver,
 * the manifest, the routes, and the last hour's numbers (SPEC-016). It only
 * reads: the manifest as dynamic-throttle:status reads it, never rebuilt, and the
 * routes as explain reads them.
 *
 * @internal
 */
final class HealthContext
{
    /** How many complete minutes the checks of what the rules saw read (decision 4). */
    public const int MINUTES = 60;

    private string|InvalidArgumentException|null $driver = null;

    private ?ManifestRead $manifest = null;

    private ?RouteMiddleware $routes = null;

    /** @var list<array{Route, list<string>}>|Throwable|null */
    private array|Throwable|null $routeList = null;

    /** @var array<string, MinuteCounts>|string|null */
    private array|string|null $lastHour = null;

    public function __construct(
        public readonly Container $container,
        public readonly Config $config,
        private readonly Clock $clock,
    ) {}

    /**
     * Unix seconds, by the package's clock.
     */
    public function now(): int
    {
        return intdiv($this->clock->microseconds(), 1_000_000);
    }

    /**
     * The counter driver as dynamic-throttle:status describes it, such as "cache, store
     * array", or why the config cannot build it (ADR-0020 point 1).
     */
    public function driver(): string|InvalidArgumentException
    {
        if ($this->driver === null) {
            try {
                $this->driver = $this->container->make(LimiterStoreFactory::class)->describe();
            } catch (InvalidArgumentException $exception) {
                $this->driver = $exception;
            }
        }

        return $this->driver;
    }

    /**
     * The manifest servers read, from the cache store, without L0 or L1, and without
     * rebuilding a missing one, as dynamic-throttle:status reads it.
     */
    public function manifest(): ManifestRead
    {
        return $this->manifest ??= $this->readManifest();
    }

    /**
     * The rules a request can match, or why they are unknown.
     *
     * @return list<CompiledRule>|string
     */
    public function rules(): array|string
    {
        $read = $this->manifest();

        return $read->manifest instanceof Manifest ? $read->manifest->rules() : (string) $read->problem();
    }

    public function routes(): RouteMiddleware
    {
        return $this->routes ??= RouteMiddleware::of($this->container);
    }

    /**
     * Every route of the app with its middleware in running order, but the package's
     * own, which are never limited (ADR-0004 point 5).
     *
     * @return list<array{Route, list<string>}>
     *
     * @throws Throwable when a route's middleware cannot be read, to every caller
     */
    public function routeList(): array
    {
        if ($this->routeList === null) {
            try {
                $routes = [];

                foreach ($this->routes()->router()->getRoutes()->getRoutes() as $route) {
                    if (! str_starts_with((string) $route->getName(), DynamicThrottleMiddleware::OWN_ROUTES)) {
                        $routes[] = [$route, $this->routes()->middleware($route)];
                    }
                }

                $this->routeList = $routes;
            } catch (Throwable $exception) {
                // A route whose middleware cannot be read, such as for a controller that cannot be
                // built, makes every check that reads the routes err. Read again, it would not throw:
                // Laravel keeps the middleware it computed before the failure.
                $this->routeList = $exception;
            }
        }

        if ($this->routeList instanceof Throwable) {
            throw $this->routeList;
        }

        return $this->routeList;
    }

    /**
     * Whether dynamic.throttle runs for the route: on the route, or as global middleware.
     *
     * @param  list<string>  $middleware  the route's
     */
    public function throttled(array $middleware): bool
    {
        return $this->routes()->global || $this->routes()->position($middleware) !== null;
    }

    /**
     * Whether except covers the route's path, so no rule applies to it (ADR-0020 point 3).
     */
    public function excepted(Route $route): bool
    {
        return Untouched::except($this->config, $route->uri()) instanceof Untouched;
    }

    /**
     * Why the checks of what the rules saw have nothing to read, or null: telemetry
     * off, or a sample rate of 0, which records nothing (AC-19).
     */
    public function telemetryOff(): ?string
    {
        if ($this->config->get('dynamic-throttle.telemetry.enabled') === false) {
            return 'dynamic-throttle.telemetry.enabled is off, so nothing is recorded.';
        }

        if (ConfigValues::sampleRate($this->config->get('dynamic-throttle.telemetry.sample_rate')) === 0.0) {
            return 'dynamic-throttle.telemetry.sample_rate is 0, so nothing is recorded.';
        }

        return null;
    }

    public function buffer(): TelemetryBuffer
    {
        return $this->container->make(TelemetryBuffer::class);
    }

    /**
     * Each rule's numbers over the last 60 complete minutes (decision 4), by rule id,
     * or why they cannot be read. A rule the manifest no longer holds may be among
     * them, on Redis, whose minutes list every rule they hold.
     *
     * @return array<string, MinuteCounts>|string
     */
    public function lastHour(): array|string
    {
        return $this->lastHour ??= $this->readLastHour();
    }

    /**
     * The last complete minute, in Unix minutes, as the flush counts it: a request
     * writes into the minute it ends in, after its response.
     */
    public function lastCompleteMinute(): int
    {
        return intdiv($this->now(), 60) - 2;
    }

    /**
     * An error's message, with its root cause's when it does not already say it, as
     * explain gives it.
     */
    public static function message(Throwable $exception): string
    {
        $root = $exception;

        while (($previous = $root->getPrevious()) instanceof Throwable) {
            $root = $previous;
        }

        $message = $exception->getMessage();

        return $root === $exception || str_contains($message, $root->getMessage()) ? $message : $message.' '.$root->getMessage();
    }

    /**
     * Items for a message: the first ten, then how many more (SPEC-016's many routes).
     *
     * @param  list<string>  $items
     */
    public static function listed(array $items, string $separator = ', '): string
    {
        $shown = array_slice($items, 0, 10);
        $more = count($items) - count($shown);

        return implode($separator, $shown).($more > 0 ? "{$separator}and {$more} more" : '');
    }

    /**
     * A route as a message names it: "GET|HEAD api/users".
     */
    public static function route(Route $route): string
    {
        return implode('|', $route->methods()).' '.$route->uri();
    }

    /**
     * A rule as a message names it: "rule 2, Users".
     */
    public static function rule(CompiledRule $rule): string
    {
        return "rule {$rule->id}, {$rule->name}";
    }

    /**
     * A time in UTC, as the flush prints it.
     *
     * @param  int  $seconds  Unix seconds
     */
    public static function time(int $seconds, string $format = 'Y-m-d H:i:s'): string
    {
        return Date::createFromTimestamp($seconds, 'UTC')->format($format).' UTC';
    }

    private function readManifest(): ManifestRead
    {
        try {
            $store = $this->config->get('dynamic-throttle.store');
            $json = $this->container->make(CacheFactory::class)
                ->store(is_string($store) && $store !== '' ? $store : null)
                ->get($this->container->make(KeyBuilder::class)->manifest());
            $manifest = is_string($json) ? ManifestCodec::decode($json) : null;
        } catch (Throwable $exception) {
            return ManifestRead::unreadable(self::message($exception));
        }

        return $manifest instanceof Manifest ? ManifestRead::of($manifest) : ManifestRead::missing();
    }

    /**
     * @return array<string, MinuteCounts>|string
     */
    private function readLastHour(): array|string
    {
        $rules = $this->rules();

        if (is_string($rules)) {
            return $rules;
        }

        $ruleIds = array_map(fn (CompiledRule $rule): string => $rule->id, $rules);
        $last = $this->lastCompleteMinute();
        $totals = [];

        try {
            $buffer = $this->buffer();

            foreach (range($last - self::MINUTES + 1, $last) as $minute) {
                foreach ($buffer->counts($minute, $ruleIds) as $ruleId => $counts) {
                    $totals[(string) $ruleId] = $this->add($totals[(string) $ruleId] ?? null, $counts);
                }
            }
        } catch (StorageUnavailableException $exception) {
            return 'The counter store cannot be read: '.self::message($exception);
        }

        return $totals;
    }

    private function add(?MinuteCounts $sum, MinuteCounts $counts): MinuteCounts
    {
        return $sum instanceof MinuteCounts ? new MinuteCounts(
            $sum->hits + $counts->hits,
            $sum->blocks + $counts->blocks,
            $sum->wouldBlocks + $counts->wouldBlocks,
            $sum->guests + $counts->guests,
            $sum->skipped + $counts->skipped,
            $sum->ignored + $counts->ignored,
        ) : $counts;
    }
}
