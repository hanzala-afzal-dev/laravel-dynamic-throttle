<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Contracts;

use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;

/**
 * What one request is, for each subject an exemption can name (SPEC-009).
 * ExemptionSet asks only for what the rule's exemptions need.
 */
interface ExemptionSubjects
{
    /**
     * The client IP as Request::ip() returns it, or null when there is none.
     *
     * @throws IdentityUnavailableException
     */
    public function ip(): ?string;

    /**
     * The hash of the user's "Class:id" (IdentityHasher), or null for a guest.
     *
     * @throws IdentityUnavailableException
     */
    public function user(): ?string;

    /**
     * The hash of the custom scope's value (IdentityHasher), or null when the
     * request has none.
     *
     * @throws IdentityUnavailableException
     */
    public function custom(string $scope): ?string;
}
