<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine;

use InvalidArgumentException;
use LogicException;

/**
 * Builds every cache and Redis key the package uses (CLAUDE.md hard rule).
 */
final readonly class KeyBuilder
{
    public function __construct(
        public string $prefix = 'dt:',
    ) {
        // Memcached keys cannot contain spaces or control characters.
        if (preg_match('/\A[A-Za-z0-9:_.\-]{0,32}\z/', $prefix) !== 1) {
            throw new InvalidArgumentException('The key prefix must be at most 32 characters from A-Z, a-z, 0-9, ":", "_", "." and "-".');
        }
    }

    /**
     * The base key of one counter; the Redis driver keeps one hash here (ADR-0006).
     */
    public function counterBase(CounterKey $key, int $windowSeconds): string
    {
        return "{$this->prefix}c:{$key->ruleId}:{$windowSeconds}:{$key->identityHash}";
    }

    /**
     * The key for one window of one counter; the cache driver keeps one key per window (ADR-0006).
     */
    public function counter(CounterKey $key, int $windowSeconds, int $windowIndex): string
    {
        return $this->counterBase($key, $windowSeconds).':'.$windowIndex;
    }

    /**
     * The compiled rules every request reads (ADR-0007, SPEC-006). Counter keys start with
     * "c:", so the two never collide.
     */
    public function manifest(): string
    {
        return "{$this->prefix}m";
    }

    /**
     * The lock that serializes compiles, so they never overwrite each other out of order.
     */
    public function manifestLock(): string
    {
        return $this->manifest().':lock';
    }

    /**
     * The lock that lets one request rebuild a missing manifest after its response.
     */
    public function manifestRebuild(): string
    {
        return $this->manifest().':rebuild';
    }

    /**
     * A minute's telemetry on Redis: one hash, a field per rule and metric (SPEC-015).
     *
     * @param  int  $minute  Unix minutes
     */
    public function telemetryMinute(int $minute): string
    {
        throw new LogicException('SPEC-015: not built yet.');
    }

    /**
     * One rule's metric for a minute on a cache store: hits, blocks or would_blocks (SPEC-015).
     *
     * @param  int  $minute  Unix minutes
     */
    public function telemetryCount(int $minute, string $ruleId, string $metric): string
    {
        throw new LogicException('SPEC-015: not built yet.');
    }

    /**
     * The clients a rule blocked or would have blocked in an hour, on Redis (SPEC-015).
     *
     * @param  int  $hour  Unix hours
     */
    public function offenders(string $ruleId, int $hour): string
    {
        throw new LogicException('SPEC-015: not built yet.');
    }

    /**
     * How many different clients those were, as a HyperLogLog on Redis (SPEC-015).
     *
     * @param  int  $hour  Unix hours
     */
    public function clients(string $ruleId, int $hour): string
    {
        throw new LogicException('SPEC-015: not built yet.');
    }

    /**
     * The last minute the flush moved (SPEC-015).
     */
    public function telemetryFlushed(): string
    {
        throw new LogicException('SPEC-015: not built yet.');
    }

    /**
     * When the counter store's cooldown started, in APCu (SPEC-012). The PHP-FPM pools
     * of a server share APCu, so the key names the app: another app's outage is not
     * this one's.
     */
    public function cooldown(string $app): string
    {
        return "{$this->prefix}cooldown:{$app}";
    }

    /**
     * When a cause of a failure was last reported, in APCu, for one app (SPEC-012).
     */
    public function reported(string $app, string $cause): string
    {
        return "{$this->prefix}reported:{$app}:{$cause}";
    }

    /**
     * The manifest a server last read, in APCu (SPEC-028). The key names the app, as the
     * cooldown's does, and the package's version, so a deploy in place never reads
     * objects that another version's classes wrote.
     */
    public function localManifest(string $app, string $version): string
    {
        return "{$this->prefix}manifest:{$app}:{$version}";
    }

    /**
     * The claim that lets one process of the server refresh the manifest's expired entry
     * in APCu while the others serve it (SPEC-028).
     */
    public function localManifestRefresh(string $app, string $version): string
    {
        return $this->localManifest($app, $version).':refresh';
    }
}
