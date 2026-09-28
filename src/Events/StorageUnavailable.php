<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Events;

use Throwable;

/**
 * The counter store failed (ADR-0010 point 1, SPEC-012), on an attempt or on
 * a release. Dispatched once the response is sent, at most once per request:
 * with the cooldown on, about once per cooldown on each server, or in each
 * Octane worker without APCu. It carries plain values, so a queued listener
 * gets it back from serialize() (decision 3).
 */
final readonly class StorageUnavailable
{
    public function __construct(
        /**
         * The error's class: the root cause's when one exception wraps another,
         * such as the config mistake behind a store that could not be built.
         *
         * @var class-string<Throwable>
         */
        public string $exceptionClass,
        /** The error's message, the root cause's as for the class. */
        public string $message,
        /** It failed releasing a count (ADR-0021). The rejected request kept its 429. */
        public bool $release,
        /** How long the store is skipped: failure.cooldown_seconds, 0 when the cooldown is off. */
        public int $cooldownSeconds,
    ) {}
}
