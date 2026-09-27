<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Engine\Contracts\FailureMemory;
use Afzal\DynamicThrottle\Engine\KeyBuilder;

/**
 * Failures remembered in APCu, where the PHP-FPM workers of one server share
 * them (ADR-0010 point 4, SPEC-012). Every app of the server has entries of
 * its own. A full or disabled APCu keeps nothing, and the process still
 * remembers.
 */
final readonly class ApcuFailureMemory implements FailureMemory
{
    public function __construct(
        private KeyBuilder $keys,
        /** The app among those of the server, whose PHP-FPM pools share APCu. */
        private string $app,
    ) {}

    /**
     * APCu is loaded and enabled here. The CLI needs apc.enable_cli=1.
     */
    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function cooldownStartedAt(): ?int
    {
        return $this->fetch($this->keys->cooldown($this->app));
    }

    public function startCooldown(int $at, int $seconds): void
    {
        $this->store($this->keys->cooldown($this->app), $at, $seconds);
    }

    public function reportedAt(string $cause): ?int
    {
        return $this->fetch($this->keys->reported($this->app, $cause));
    }

    public function markReported(string $cause, int $at, int $seconds): void
    {
        $this->store($this->keys->reported($this->app, $cause), $at, $seconds);
    }

    private function fetch(string $key): ?int
    {
        $value = apcu_fetch($key, $found);

        return $found && is_int($value) ? $value : null;
    }

    /**
     * Kept a second longer than it matters: APCu counts whole seconds of its own clock.
     */
    private function store(string $key, int $value, int $seconds): void
    {
        apcu_store($key, $value, $seconds + 1);
    }
}
