<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * A cache store that is down while $down is set, as Redis is during an outage:
 * every read, write and lock throws. It keeps its keys meanwhile, as Redis does
 * when it comes back with its data (SPEC-029 AC-1).
 */
final class OutageCache extends ArrayStore
{
    public const string DOWN = 'Connection refused';

    public bool $down = false;

    /**
     * Makes a new one the package's cache store, which holds the manifest and,
     * through the cache driver, the counters, as Redis usually holds both.
     */
    public static function asPackageStore(): self
    {
        $store = new self;
        Cache::extend('outage', fn (): Repository => new Repository($store));
        config()->set('cache.stores.outage', ['driver' => 'outage']);
        config()->set('dynamic-throttle.store', 'outage');
        app()->forgetScopedInstances();

        return $store;
    }

    public function get($key): mixed
    {
        $this->failWhileDown();

        return parent::get($key);
    }

    public function put($key, $value, $seconds): bool
    {
        $this->failWhileDown();

        return parent::put($key, $value, $seconds);
    }

    public function increment($key, $value = 1): int|bool
    {
        $this->failWhileDown();

        return parent::increment($key, $value);
    }

    public function decrement($key, $value = 1): int|bool
    {
        $this->failWhileDown();

        return parent::decrement($key, $value);
    }

    public function forever($key, $value): bool
    {
        $this->failWhileDown();

        return parent::forever($key, $value);
    }

    public function forget($key): bool
    {
        $this->failWhileDown();

        return parent::forget($key);
    }

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        $this->failWhileDown();

        return parent::lock($name, $seconds, $owner);
    }

    public function restoreLock($name, $owner): Lock
    {
        $this->failWhileDown();

        return parent::restoreLock($name, $owner);
    }

    private function failWhileDown(): void
    {
        if ($this->down) {
            throw new RuntimeException(self::DOWN);
        }
    }
}
