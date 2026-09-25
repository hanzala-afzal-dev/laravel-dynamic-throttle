<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Engine\Exemptions\ExemptionSet;
use Afzal\DynamicThrottle\Engine\Identity\IdentityHasher;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Http\RequestIdentityResolver;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;

/**
 * Requests, rules and identities for the SPEC-008 and SPEC-009 feature tests.
 */
final class Identities
{
    /**
     * @param  array<string, string>  $headers
     */
    public static function request(?string $ip = '203.0.113.7', array $headers = []): Request
    {
        $server = ['REMOTE_ADDR' => $ip];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/api/x', 'GET', server: $server);
    }

    /**
     * A request whose IP Symfony cannot read: the app trusts the proxy for both
     * Forwarded and X-Forwarded-For, and the two disagree. Trusted proxies are
     * static on Symfony's Request, so the test resets them afterwards.
     */
    public static function unreadableIpRequest(): Request
    {
        Request::setTrustedProxies(['203.0.113.7'], Request::HEADER_FORWARDED | Request::HEADER_X_FORWARDED_FOR);

        return self::request('203.0.113.7', ['Forwarded' => 'for=198.51.100.1', 'X-Forwarded-For' => '198.51.100.2']);
    }

    public static function rule(string $scope, string $missing = 'ip', ?string $parameter = null, string $id = '1', int $max = 60): CompiledRule
    {
        return CompiledRule::fromArray(ManifestFixtures::rule([
            'id' => $id,
            'scope' => $scope,
            'scope_parameter' => $parameter,
            'on_missing_identity' => $missing,
            'max_attempts' => $max,
        ]));
    }

    /**
     * The container's resolver for a request, with a manifest's exemptions if given (SPEC-009).
     */
    public static function resolver(Request $request, ?ExemptionSet $exemptions = null): RequestIdentityResolver
    {
        return app(RequestIdentityResolver::class, $exemptions instanceof ExemptionSet ? ['request' => $request, 'exemptions' => $exemptions] : ['request' => $request]);
    }

    /**
     * The identity the container's resolver gives a request for a rule.
     */
    public static function of(Request $request, CompiledRule $rule): ?string
    {
        return self::resolver($request)->resolve($rule);
    }

    /**
     * An identity string as the resolver hashes it, for the fallbacks the spec names.
     */
    public static function hashed(string $identity): string
    {
        return app(IdentityHasher::class)->hash($identity);
    }

    /**
     * The cache driver on an array store, with the clock frozen at T0+10.
     */
    public static function store(ArrayStore $array = new ArrayStore): CacheLimiterStore
    {
        $clock = new FakeClock;
        $clock->setSeconds(FakeClock::T0 + 10);

        return new CacheLimiterStore(new Repository($array), new KeyBuilder('dt:'), $clock);
    }
}
