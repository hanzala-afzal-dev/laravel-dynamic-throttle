<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Contracts;

use Illuminate\Http\Request;
use Stringable;

/**
 * Resolves a custom scope, such as a tenant or a plan (ADR-0009, SPEC-008).
 * Register one with DynamicThrottle::scope('tenant', TenantScope::class).
 */
interface ScopeResolver
{
    /**
     * Who the request belongs to under this scope. null or '' means the request
     * has no identity for it: the rule then falls back to the client IP, or is
     * skipped, as its on_missing_identity says.
     */
    public function resolve(Request $request): string|int|Stringable|null;
}
