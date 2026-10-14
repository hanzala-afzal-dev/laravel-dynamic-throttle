<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

/**
 * The app's default guard, as it stood once every provider booted. Laravel's auth
 * middleware makes the guard it authenticates with the default for the rest of a
 * request, so in a dashboard request behind auth:admin, the configured default
 * names the admin's guard. A user id alone means a user of this one, in exemptions
 * and in explain (SPEC-017 AC-25).
 *
 * @internal
 */
final readonly class DefaultGuard
{
    public function __construct(
        public ?string $name,
    ) {}
}
