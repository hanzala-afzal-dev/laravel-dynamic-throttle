<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\FailureMemory;
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
    /** An unreadable manifest is reported at most this often per process, or per server with APCu, so an outage cannot flood the error tracker. */
    private const int REPORT_EVERY_SECONDS = 30;

    /** The cause the server's processes share the last report under. Other causes are hashes, so it is never one of them. */
    private const string REPORT_CAUSE = 'manifest';

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
        /** Where the server's processes share when the manifest was last reported, or null for this process alone. */
        private ?FailureMemory $reports = null,
    ) {}

    public function current(): Manifest
    {
        $now = $this->clock->microseconds();
        $memory = ManifestMemory::$manifest;

        if ($memory instanceof Manifest && $this->isFresh(ManifestMemory::$readAt, $now)) {
            return $memory;
        }

        $snapshot = $this->local?->fetch();

        if ($snapshot instanceof ManifestSnapshot) {
            $manifest = $this->isFresh($snapshot->readAt, $now) ? $this->build($snapshot) : null;

            if ($manifest instanceof Manifest) {
                // Kept in L0 with the entry's read time, so L0 never outlives L1's freshness.
                ManifestMemory::$readAt = $snapshot->readAt;

                return ManifestMemory::$manifest = $manifest;
            }

            // Expired. One process of the server reads the store, and the others go on with
            // the last known manifest meanwhile, so a slow store holds up one request (AC-15).
            $last = $this->local->claim($this->refreshSeconds) ? null : $this->lastKnown($memory, $snapshot);

            if ($last instanceof Manifest) {
                return $last;
            }
        }

        $read = $this->readStore();
        // After the store answered, which a slow store makes later than $now.
        $readAt = $this->clock->microseconds();

        if ($read instanceof Manifest) {
            $this->share($read, $readAt);
            ManifestMemory::$readAt = $readAt;

            return ManifestMemory::$manifest = $read;
        }

        $last = $this->lastKnown($memory, $snapshot)?->stale();

        if ($last instanceof Manifest) {
            // Kept first, before the report and the rebuild's lock can wait on the store too, so
            // the store is tried again after refresh_seconds: from this process, and through L1
            // from every process of the server.
            $this->share($last, $readAt);
            ManifestMemory::$readAt = $readAt;
            ManifestMemory::$manifest = $last;
        }

        $this->report($read, $now);
        $this->scheduleRebuild();

        // Without a last known manifest, nothing is kept, so the next call looks for the rebuilt one at once.
        return $last ?? Manifest::missing();
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
     * The manifest in the cache store, or why there is none.
     */
    private function readStore(): Manifest|Throwable
    {
        try {
            $json = $this->cache->get($this->keys->manifest());

            return is_string($json)
                ? ManifestCodec::decode($json)
                : new ManifestUnavailable('The manifest is missing from the cache. One request will rebuild it after its response.');
        } catch (InvalidManifest $exception) {
            return $exception;
        } catch (Throwable $exception) {
            return new ManifestUnavailable('The manifest could not be read: '.$exception->getMessage(), previous: $exception);
        }
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
     * The manifest an L1 entry holds. A snapshot was checked when it came from the entry,
     * so this guards the repository's promise never to throw, and nothing more.
     */
    private function build(ManifestSnapshot $snapshot): ?Manifest
    {
        try {
            return $snapshot->manifest();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Keeps the manifest in L1 for the server's other processes. One that cannot be written
     * there, such as one holding a number JSON cannot encode, is still served (SPEC-028 AC-10).
     */
    private function share(Manifest $manifest, int $readAt): void
    {
        try {
            $this->local?->store(ManifestSnapshot::of($manifest, $readAt));
        } catch (Throwable) {
            // Not kept: the server's next request reads the store.
        }
    }

    /**
     * At most once per REPORT_EVERY_SECONDS. On PHP-FPM, every request starts with an
     * empty process, so only what the server's processes share can hold that (SPEC-029).
     */
    private function report(Throwable $problem, int $now): void
    {
        foreach ([ManifestMemory::$reportedAt, $this->reports?->reportedAt(self::REPORT_CAUSE)] as $reportedAt) {
            if ($reportedAt !== null && $now - $reportedAt < self::REPORT_EVERY_SECONDS * 1_000_000) {
                return;
            }
        }

        ManifestMemory::$reportedAt = $now;
        $this->reports?->markReported(self::REPORT_CAUSE, $now, self::REPORT_EVERY_SECONDS);

        try {
            report($problem);
        } catch (Throwable) {
            // The app's exception handler failed too, so there is nowhere left to report it.
        }
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
