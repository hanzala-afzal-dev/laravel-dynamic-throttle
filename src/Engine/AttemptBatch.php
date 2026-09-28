<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine;

use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;

/**
 * What counting a request's planned attempts did, in plan order (ADR-0026,
 * SPEC-027). The attempts stop at the first enforcing rejection, or at the
 * failure. A failure is part of the result, since the attempts before it may
 * have counted.
 */
final readonly class AttemptBatch
{
    /**
     * @param  list<Attempt>  $attempts  the attempts made, in plan order
     * @param  list<bool>  $released  for each attempt made, whether its count was released
     * @param  array<int, StorageUnavailableException>  $releaseFailures  the releases that failed, by the attempt's position
     * @param  StorageUnavailableException|null  $failure  the store failed on the planned attempt right after the last one made
     */
    public function __construct(
        public array $attempts = [],
        public array $released = [],
        public array $releaseFailures = [],
        public ?StorageUnavailableException $failure = null,
    ) {}
}
