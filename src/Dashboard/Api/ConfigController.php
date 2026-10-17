<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\DynamicThrottle;
use Afzal\DynamicThrottle\Support\LimiterStoreFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * GET api/config (SPEC-031): the configuration servers run with, for the Settings
 * page: the counters' driver as dynamic-throttle:status describes it, the package's
 * config as the app resolved it, the custom scopes and strict mode. It reads no
 * database and no store, so it answers while either is down (decision 2).
 *
 * @internal
 */
final readonly class ConfigController
{
    /** A key an app adds that looks like it holds a secret (decision 3). */
    private const string SECRET_KEY = '/password|secret|token/i';

    public function __construct(
        private Config $config,
        private LimiterStoreFactory $stores,
        private DynamicThrottle $throttle,
    ) {}

    public function __invoke(): JsonResponse
    {
        try {
            [$driver, $error] = [$this->stores->describe(), null];
        } catch (Throwable $exception) {
            // The rest still shows, so the page can say what is wrong (AC-2).
            [$driver, $error] = [null, $exception->getMessage()];
        }

        $store = $this->config->get('dynamic-throttle.store');

        return new JsonResponse([
            'driver' => $driver,
            'driver_error' => $error,
            'manifest_store' => is_string($store) && $store !== '' ? $store : $this->config->get('cache.default'),
            'config' => $this->shown($this->config->get('dynamic-throttle', [])),
            'scopes' => $this->throttle->scopeNames(),
            'strict' => $this->throttle->isStrict(),
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * A config value as JSON can hold it: "(hidden)" under a key that looks secret, and
     * the type of a value JSON cannot hold, such as "(Closure)".
     */
    private function shown(mixed $value, int|string|null $key = null): mixed
    {
        if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1) {
            return '(hidden)';
        }

        if (is_array($value)) {
            $shown = [];

            foreach ($value as $name => $item) {
                $shown[$name] = $this->shown($item, $name);
            }

            return $shown;
        }

        if (is_float($value) && ! is_finite($value)) {
            return (string) $value;
        }

        return $value === null || is_scalar($value) ? $value : '('.get_debug_type($value).')';
    }
}
