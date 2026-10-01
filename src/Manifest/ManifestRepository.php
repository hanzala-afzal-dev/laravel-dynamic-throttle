<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\Contracts\LocalManifestCache;
use Afzal\DynamicThrottle\Manifest\Contracts\ManifestRebuilder;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * The request path's view of the rules (ADR-0007, SPEC-006). It never touches
 * the database and never throws: a missing, corrupt or unreadable manifest
 * falls back to the last one this process or server read, or to no rules at
 * all, and one request rebuilds it after the response.
 *
 * It reads L0, the process's memory, then L1, which the server's processes
 * share (SPEC-028), then L2, the cache store.
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
        /** L1, shared by the server's processes, or null to read the store after L0 (SPEC-028). */
        private ?LocalManifestCache $local = null,
    ) {}

    public function current(): Manifest
    {
        $now = $this->clock->microseconds();
        $memory = ManifestMemory::$manifest;

        if ($memory instanceof Manifest && $this->isFresh(ManifestMemory::$readAt, $now)) {
            return $memory;
        }

        $snapshot = $this->local?->fetch();

        if ($snapshot instanceof ManifestSnapshot && $this->isFresh($snapshot->readAt, $now)) {
            $manifest = $this->build($snapshot);

            if ($manifest instanceof Manifest) {
                // Kept in L0 with the entry's read time, so L0 never outlives L1's freshness.
                ManifestMemory::$readAt = $snapshot->readAt;

                return ManifestMemory::$manifest = $manifest;
            }
        }

        try {
            $json = $this->cache->get($this->keys->manifest());

            if (is_string($json)) {
                $manifest = ManifestCodec::decode($json);
                $this->local?->store(ManifestSnapshot::of($manifest, $now));
                ManifestMemory::$readAt = $now;

                return ManifestMemory::$manifest = $manifest;
            }

            $problem = new ManifestUnavailable('The manifest is missing from the cache. One request will rebuild it after its response.');
        } catch (InvalidManifest $exception) {
            $problem = $exception;
        } catch (Throwable $exception) {
            $problem = new ManifestUnavailable('The manifest could not be read: '.$exception->getMessage(), previous: $exception);
        }

        $this->report($problem, $now);
        $this->scheduleRebuild();
        $last = $this->lastKnown($memory, $snapshot);

        if (! $last instanceof Manifest) {
            // Not kept anywhere, so the next call looks for the rebuilt manifest at once.
            return Manifest::missing();
        }

        // Keep the last known rules, and try the store again after refresh_seconds: from
        // this process, and through L1 from every process of the server.
        $stale = $last->stale();
        $this->local?->store(ManifestSnapshot::of($stale, $now));
        ManifestMemory::$readAt = $now;

        return ManifestMemory::$manifest = $stale;
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

    /**
     * Read within refresh_seconds. A read time in the future, after the clock went back,
     * is not fresh, so the clock's step never freezes the rules (SPEC-028).
     */
    private function isFresh(int $readAt, int $now): bool
    {
        return $now >= $readAt && $now - $readAt < $this->refreshSeconds * 1_000_000;
    }

    /**
     * The newer of L0's and L1's manifests. Versions only grow (SPEC-006 AC-3), so a
     * process never trades the server's newer rules for its own older ones (SPEC-028
     * decision 7).
     */
    private function lastKnown(?Manifest $memory, ?ManifestSnapshot $snapshot): ?Manifest
    {
        if ($snapshot instanceof ManifestSnapshot && (! $memory instanceof Manifest || $snapshot->version > $memory->version)) {
            return $this->build($snapshot) ?? $memory;
        }

        return $memory;
    }

    /**
     * The manifest an L1 entry holds, or null for an entry that cannot build one, such as
     * a rule whose class changed since the entry was stored (SPEC-028 AC-9).
     */
    private function build(ManifestSnapshot $snapshot): ?Manifest
    {
        try {
            return $snapshot->manifest();
        } catch (Throwable) {
            return null;
        }
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
