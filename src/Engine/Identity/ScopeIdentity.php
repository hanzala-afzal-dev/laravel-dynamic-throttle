<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Identity;

use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Closure;

/**
 * The identity a rule counts a request under, before hashing (ADR-0009,
 * ADR-0022, SPEC-008). A present identity and its IP fallback use different
 * prefixes, so they never share a counter.
 */
final class ScopeIdentity
{
    /**
     * @param  ?string  $value  the scope's value for this request, null or '' when it has none; ip and global ignore it
     * @param  Closure(): string  $ip  the client IP, grouped (ClientIp). Called only when the identity needs it,
     *                                 because reading it can fail, and a rule that needs no IP must still resolve.
     * @return ?string null when the rule does not apply to the request: a missing identity on a skip rule
     */
    public static function for(CompiledRule $rule, ?string $value, Closure $ip): ?string
    {
        return match ($rule->scope) {
            Scope::Ip => 'ip:'.$ip(),
            Scope::Global => 'global',
            Scope::User => self::identity($rule, $value, 'user:', 'guest:', $ip),
            Scope::ApiToken => self::identity($rule, $value, 'token:', 'anon:', $ip),
            Scope::Header => self::identity($rule, $value, 'header:', 'missing:', $ip),
            Scope::Custom => self::identity($rule, $value, 'custom:', 'missing:', $ip),
        };
    }

    /**
     * @param  Closure(): string  $ip
     */
    private static function identity(CompiledRule $rule, ?string $value, string $prefix, string $fallbackPrefix, Closure $ip): ?string
    {
        if ($value !== null && $value !== '') {
            return $prefix.$value;
        }

        return $rule->onMissingIdentity === MissingIdentity::Skip ? null : $fallbackPrefix.$ip();
    }
}
