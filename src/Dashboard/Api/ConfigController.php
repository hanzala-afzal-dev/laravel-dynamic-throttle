<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\DynamicThrottle;
use Afzal\DynamicThrottle\Support\LimiterStoreFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Throwable;

/**
 * GET api/config (SPEC-031): the configuration servers run with, for the Settings
 * page: the counters' driver as dynamic-throttle:status describes it, the package's
 * config as the app resolved it, the custom scopes and strict mode. It reads no
 * database and no store (decision 2).
 *
 * @internal
 */
final readonly class ConfigController
{
    /** What a key the package does not define shows, whatever it holds (decision 3, AC-7). */
    private const string UNUSED = '(not used by the package)';

    /** How deep a value shows, so a deep one never breaks the JSON (AC-8). */
    private const int DEPTH = 32;

    public function __construct(
        private Config $config,
        private Container $container,
        private DynamicThrottle $throttle,
    ) {}

    public function __invoke(): JsonResponse
    {
        [$driver, $error] = $this->driver();
        $store = $this->config->get('dynamic-throttle.store');

        return new JsonResponse([
            'driver' => $driver,
            'driver_error' => $error,
            'manifest_store' => is_string($store) && $store !== '' ? $store : $this->config->get('cache.default'),
            'config' => $this->shown($this->config->get('dynamic-throttle', []), require __DIR__.'/../../../config/dynamic-throttle.php'),
            'scopes' => $this->throttle->scopeNames(),
            'strict' => $this->throttle->isStrict(),
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * The counters' driver, built as requests build it, or why it cannot be: a config
     * mistake's own message, and for anything else only its class, since its message
     * may name a host or a password (AC-8). The rest of the page still shows.
     *
     * @return array{?string, ?string}
     */
    private function driver(): array
    {
        try {
            return [$this->container->make(LimiterStoreFactory::class)->describe(), null];
        } catch (InvalidArgumentException $mistake) {
            return [null, $mistake->getMessage()];
        } catch (Throwable $exception) {
            $this->container->make(ExceptionHandler::class)->report($exception);

            return [null, 'The counter driver could not be built: '.$exception::class.'. The app\'s log has the details.'];
        }
    }

    /**
     * A config value as the page shows it: where the package defines a map, its own
     * keys with their values and any other key as unused; elsewhere the value whole.
     */
    private function shown(mixed $value, mixed $default, int $depth = 0): mixed
    {
        if (! is_array($value) || ! is_array($default) || $default === [] || array_is_list($default)) {
            return $this->plain($value, $depth);
        }

        $shown = [];

        foreach ($value as $key => $item) {
            $shown[$key] = array_key_exists($key, $default) ? $this->shown($item, $default[$key], $depth + 1) : self::UNUSED;
        }

        return $shown;
    }

    /**
     * A value as JSON can hold it: the type of one it cannot, such as "(Closure)", and
     * no deeper than DEPTH.
     */
    private function plain(mixed $value, int $depth): mixed
    {
        if ($depth > self::DEPTH) {
            return '(nested too deep)';
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->plain($item, $depth + 1), $value);
        }

        if (is_float($value) && ! is_finite($value)) {
            return (string) $value;
        }

        return $value === null || is_scalar($value) ? $value : '('.get_debug_type($value).')';
    }
}
