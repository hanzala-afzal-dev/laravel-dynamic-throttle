<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\Contracts\ManifestRebuilder;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * The request path's view of the rules (ADR-0007, SPEC-006). It never touches
 * the database and never throws: a missing, corrupt or unreadable manifest
 * falls back to the last one this process read, or to no rules at all, and
 * one request rebuilds it after the response.
 */
final readonly class ManifestRepository
{
    /** An unreadable manifest is reported at most this often per process, so an outage cannot flood the error tracker. */
    private const int REPORT_EVERY_SECONDS = 30;

    /** How long the lock lasts that lets one request, across servers, schedule the rebuild. */
    private const int REBUILD_LOCK_SECONDS = 30;

    public function __construct(
        private Repository $cache,
        private KeyBuilder $keys,
        private Clock $clock,
        private ManifestRebuilder $rebuilder,
        private Application $app,
        private int $refreshSeconds,
    ) {}

    public function current(): Manifest
    {
        $now = $this->clock->microseconds();
        $memory = ManifestMemory::$manifest;

        if ($memory instanceof Manifest && $now - ManifestMemory::$readAt < $this->refreshSeconds * 1_000_000) {
            return $memory;
        }

        try {
            $json = $this->cache->get($this->keys->manifest());

            if (is_string($json)) {
                ManifestMemory::$readAt = $now;

                return ManifestMemory::$manifest = ManifestCodec::decode($json);
            }

            $problem = new ManifestUnavailable('The manifest is missing from the cache. One request will rebuild it after its response.');
        } catch (InvalidManifest $exception) {
            $problem = $exception;
        } catch (Throwable $exception) {
            $problem = new ManifestUnavailable('The manifest could not be read: '.$exception->getMessage(), previous: $exception);
        }

        $this->report($problem, $now);
        $this->scheduleRebuild();

        if (! $memory instanceof Manifest) {
            // Not kept in memory, so the next call looks for the rebuilt manifest at once.
            return Manifest::missing();
        }

        // Keep the last known rules, and try the store again after refresh_seconds.
        ManifestMemory::$readAt = $now;

        return ManifestMemory::$manifest = $memory->stale();
    }

    /**
     * Clears what this process remembers. For tests only: clearing it between Octane
     * requests would make every request read the store.
     */
    public static function flushState(): void
    {
        ManifestMemory::$manifest = null;
        ManifestMemory::$readAt = 0;
        ManifestMemory::$reportedAt = null;
    }

    private function report(Throwable $problem, int $now): void
    {
        if (ManifestMemory::$reportedAt !== null && $now - ManifestMemory::$reportedAt < self::REPORT_EVERY_SECONDS * 1_000_000) {
            return;
        }

        ManifestMemory::$reportedAt = $now;
        report($problem);
    }

    /**
     * The request that wins the lock rebuilds after its response: the only database access
     * allowed on a request thread (ADR-0007). Without a working store, the scheduled
     * compile heals the manifest instead.
     */
    private function scheduleRebuild(): void
    {
        try {
            if (! $this->cache->add($this->keys->manifestRebuild(), true, self::REBUILD_LOCK_SECONDS)) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $rebuilder = $this->rebuilder;

        $this->app->terminating(static function () use ($rebuilder): void {
            try {
                $rebuilder->rebuild();
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
