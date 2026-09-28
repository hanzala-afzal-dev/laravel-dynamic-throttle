<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stacking;

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\AttemptBatch;
use Afzal\DynamicThrottle\Engine\Contracts\BatchLimiterStore;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\Internal\AttemptGuard;

/**
 * The batch's behavior for any LimiterStore, with one call per attempt and
 * per release (ADR-0026, SPEC-027): for the cache driver, Redis Cluster, and
 * stores an app wrote.
 */
final readonly class OneCallPerAttempt implements BatchLimiterStore
{
    public function __construct(
        private LimiterStore $store,
    ) {}

    public function attemptAll(array $attempts, bool $releaseAll): AttemptBatch
    {
        // The checks each attempt makes, before the first one counts (SPEC-002 AC-16).
        foreach ($attempts as $planned) {
            AttemptGuard::check($planned->maxAttempts, $planned->windowSeconds);
        }

        $made = [];

        foreach ($attempts as $planned) {
            try {
                $attempt = $this->store->attempt($planned->key, $planned->maxAttempts, $planned->windowSeconds);
            } catch (StorageUnavailableException $exception) {
                // The counts made so far stay: releasing them would only fail again, and a
                // kept count is stricter, never looser (SPEC-004).
                return new AttemptBatch($made, array_fill(0, count($made), false), failure: $exception);
            }

            $made[] = $attempt;

            if (! $attempt->allowed && $planned->enforces) {
                return $this->release($made);
            }
        }

        return $releaseAll ? $this->release($made) : new AttemptBatch($made, array_fill(0, count($made), false));
    }

    /**
     * Releases every count made, dry-run rules' included (ADR-0021). A rejected
     * attempt was never counted. A failed release is listed, and the others are
     * still tried: the store just counted for this request.
     *
     * @param  list<Attempt>  $made
     */
    private function release(array $made): AttemptBatch
    {
        $released = [];
        $failures = [];

        foreach ($made as $position => $attempt) {
            if (! $attempt->allowed) {
                $released[] = false;

                continue;
            }

            try {
                $this->store->release($attempt);
                $released[] = true;
            } catch (StorageUnavailableException $exception) {
                $released[] = false;
                $failures[$position] = $exception;
            }
        }

        return new AttemptBatch($made, $released, $failures);
    }
}
