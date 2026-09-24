<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Stacking\EvaluationOutcome;
use Afzal\DynamicThrottle\Engine\Stacking\RuleAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestAdmin;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\Identities;
use Afzal\DynamicThrottle\Tests\Support\PlanFromConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/*
| SPEC-008 AC-1 to AC-13: the identities one request counts under, through the
| container's resolver, with the package's config and Laravel's own auth.
*/

beforeEach(function (): void {
    // Hashing is keyed, as in any Laravel app (ADR-0024).
    config()->set('app.key', 'base64:3u4DMqUd5sEWtS4w9UqJ7Qw/qMCRnGFhqRuSN40wNJA=');
});

it('AC-1: gives requests from one IPv4 address one identity, and another address another', function (): void {
    $rule = Identities::rule('ip');

    expect(Identities::of(Identities::request('203.0.113.7'), $rule))
        ->toBe(Identities::of(Identities::request('203.0.113.7'), $rule))
        ->not->toBe(Identities::of(Identities::request('203.0.113.8'), $rule));
});

it('AC-2: groups IPv6 clients by scopes.ip.ipv6_prefix', function (): void {
    $rule = Identities::rule('ip');
    $identity = fn (string $ip): ?string => Identities::of(Identities::request($ip), $rule);

    $default = [$identity('2001:db8:1:2::1'), $identity('2001:db8:1:2:ffff::9'), $identity('2001:db8:1:3::1')];
    config()->set('dynamic-throttle.scopes.ip.ipv6_prefix', 128);
    $whole = [$identity('2001:db8:1:2::1'), $identity('2001:db8:1:2:ffff::9')];
    config()->set('dynamic-throttle.scopes.ip.ipv6_prefix', 48);
    $wide = [$identity('2001:db8:1:2::1'), $identity('2001:db8:1:3::1')];

    expect($default[0])->toBe($default[1])->not->toBe($default[2])
        ->and($whole[0])->not->toBe($whole[1])
        ->and($wide[0])->toBe($wide[1]);
});

it('AC-2: counts an IPv4-mapped address as its IPv4 address, and requests without an IP as one client', function (): void {
    $rule = Identities::rule('ip');

    expect(Identities::of(Identities::request('::ffff:203.0.113.7'), $rule))->toBe(Identities::of(Identities::request('203.0.113.7'), $rule))
        ->and(Identities::of(Identities::request(null), $rule))->toBe(Identities::of(Identities::request(null), $rule))
        ->not->toBe(Identities::of(Identities::request('203.0.113.7'), $rule));
});

it('AC-3: counts a user as one client from any IP, and tells users and user types apart', function (): void {
    $rule = Identities::rule('user');

    $this->actingAs(new TestUser(['id' => 42]));
    $fromTwoIps = [Identities::of(Identities::request('203.0.113.7'), $rule), Identities::of(Identities::request('198.51.100.1'), $rule)];
    $this->actingAs(new TestUser(['id' => 43]));
    $anotherUser = Identities::of(Identities::request('203.0.113.7'), $rule);
    $this->actingAs(new TestUser(['id' => 1]));
    $user1 = Identities::of(Identities::request('203.0.113.7'), $rule);
    $this->actingAs(new TestAdmin(['id' => 1]));
    $admin1 = Identities::of(Identities::request('203.0.113.7'), $rule);

    expect($fromTwoIps[0])->toBe($fromTwoIps[1])->not->toBe($anotherUser)
        ->and($user1)->not->toBe($admin1);
});

it('AC-4: counts guests on a user rule under guest:<ip>, with the IP grouped', function (): void {
    $rule = Identities::rule('user');

    $guests = [
        Identities::of(Identities::request('203.0.113.7'), $rule),
        Identities::of(Identities::request('203.0.113.8'), $rule),
        Identities::of(Identities::request('2001:db8:1:2::1'), $rule),
    ];
    $this->actingAs(new TestUser(['id' => 42]));

    expect($guests)->toBe([
        Identities::hashed('guest:203.0.113.7'),
        Identities::hashed('guest:203.0.113.8'),
        Identities::hashed('guest:2001:db8:1:2::/64'),
    ])
        ->and(Identities::of(Identities::request('203.0.113.7'), $rule))->not->toBeIn($guests);
});

it('AC-4: skips a user rule for guests when the rule says skip', function (): void {
    expect(Identities::of(Identities::request('203.0.113.7'), Identities::rule('user', 'skip')))->toBeNull();
});

it('AC-5: by default, never looks a user up, and counts the user once auth has resolved it', function (): void {
    $rule = Identities::rule('user');
    $guest = Identities::of(Identities::request('203.0.113.7'), $rule);
    $this->actingAs(new TestUser(['id' => 42]));
    $user42 = Identities::of(Identities::request('203.0.113.7'), $rule);

    // A guard whose lookup the test can count, like a session or token guard.
    $lookups = 0;
    Auth::viaRequest('counting', function () use (&$lookups): TestUser {
        $lookups++;

        return new TestUser(['id' => 42]);
    });
    config()->set('auth.guards.counting', ['driver' => 'counting']);
    Auth::shouldUse('counting');
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $beforeAuth = Identities::of(Identities::request('203.0.113.7'), $rule);
    $lookupsBeforeAuth = $lookups;
    Auth::guard()->user(); // What auth middleware does.
    $afterAuth = Identities::of(Identities::request('203.0.113.7'), $rule);

    expect([$beforeAuth, $afterAuth])->toBe([$guest, $user42])
        ->and([$lookupsBeforeAuth, $lookups, $queries])->toBe([0, 1, 0]);
});

it('AC-6: asks each guard in scopes.user.guards in order, and the first user found wins', function (): void {
    Auth::viaRequest('nobody', fn (): ?TestUser => null);
    Auth::viaRequest('someone', fn (): TestUser => new TestUser(['id' => 42]));
    config()->set('auth.guards.api', ['driver' => 'nobody']);
    config()->set('auth.guards.web', ['driver' => 'someone']);
    config()->set('dynamic-throttle.scopes.user.guards', ['api', 'web']);
    $rule = Identities::rule('user');

    $listed = Identities::of(Identities::request('203.0.113.7'), $rule);
    config()->set('dynamic-throttle.scopes.user.guards');
    $this->actingAs(new TestUser(['id' => 42]));

    expect($listed)->toBe(Identities::of(Identities::request('203.0.113.7'), $rule))
        ->not->toBe(Identities::hashed('guest:203.0.113.7'));
});

it('AC-7: counts a bearer token as one client from any IP', function (): void {
    $rule = Identities::rule('api_token');
    $token = fn (string $ip, string $token): ?string => Identities::of(Identities::request($ip, ['Authorization' => "Bearer {$token}"]), $rule);

    expect($token('203.0.113.7', 'abc'))->toBe($token('198.51.100.1', 'abc'))
        ->not->toBe($token('203.0.113.7', 'abd'));
});

it('AC-7: counts a request without a bearer token under anon:<ip>, or skips the rule', function (array $headers): void {
    $request = Identities::request('203.0.113.7', $headers);

    expect(Identities::of($request, Identities::rule('api_token')))->toBe(Identities::hashed('anon:203.0.113.7'))
        ->and(Identities::of($request, Identities::rule('api_token', 'skip')))->toBeNull();
})->with([
    'no Authorization header' => [[]],
    'an empty bearer token' => [['Authorization' => 'Bearer ']],
    'another scheme' => [['Authorization' => 'Basic dXNlcjpwYXNz']],
]);

it('AC-8: counts each value of the named header as its own client, whatever the case of the name', function (): void {
    $rule = Identities::rule('header', parameter: 'X-Client-Id');
    $lowerCase = Identities::rule('header', parameter: 'x-client-id');
    $client = fn (string $value, CompiledRule $rule): ?string => Identities::of(Identities::request('203.0.113.7', ['X-Client-Id' => $value]), $rule);

    expect($client('c1', $rule))->toBe($client('c1', $rule))->not->toBe($client('c2', $rule))
        ->and($client('c1', $lowerCase))->toBe($client('c1', $rule));
});

it('AC-8: counts a request without the header under missing:<ip>, or skips the rule', function (array $headers): void {
    $request = Identities::request('203.0.113.7', $headers);

    expect(Identities::of($request, Identities::rule('header', parameter: 'X-Client-Id')))->toBe(Identities::hashed('missing:203.0.113.7'))
        ->and(Identities::of($request, Identities::rule('header', 'skip', 'X-Client-Id')))->toBeNull();
})->with([
    'no header' => [[]],
    'an empty header' => [['X-Client-Id' => '']],
]);

it('AC-9: gives every request the same identity on a global rule', function (): void {
    $rule = Identities::rule('global');
    $anonymous = Identities::of(Identities::request('203.0.113.7'), $rule);
    $this->actingAs(new TestUser(['id' => 42]));

    expect(Identities::of(Identities::request('198.51.100.1'), $rule))->toBe($anonymous);
});

it('AC-10: counts each value a custom scope returns as its own client', function (): void {
    DynamicThrottle::scope('plan', fn (Request $request): ?string => $request->headers->get('X-Plan'));
    $rule = Identities::rule('custom', parameter: 'plan');
    $plan = fn (string $plan): ?string => Identities::of(Identities::request('203.0.113.7', ['X-Plan' => $plan]), $rule);

    expect($plan('gold'))->toBe($plan('gold'))->not->toBe($plan('silver'));
});

it('AC-10: counts an integer and a Stringable by their string form', function (): void {
    $rule = Identities::rule('custom', parameter: 'plan');

    DynamicThrottle::scope('plan', fn (): int => 7);
    $integer = Identities::of(Identities::request(), $rule);
    DynamicThrottle::scope('plan', fn (): string => '7');
    $string = Identities::of(Identities::request(), $rule);
    DynamicThrottle::scope('plan', fn (): Stringable => str('7'));
    $stringable = Identities::of(Identities::request(), $rule);

    expect($integer)->toBe($string)->toBe($stringable);
});

it('AC-10: counts a missing custom value under missing:<ip>, or skips the rule', function (?string $value): void {
    DynamicThrottle::scope('plan', fn (): ?string => $value);
    $request = Identities::request('203.0.113.7');

    expect(Identities::of($request, Identities::rule('custom', parameter: 'plan')))->toBe(Identities::hashed('missing:203.0.113.7'))
        ->and(Identities::of($request, Identities::rule('custom', 'skip', 'plan')))->toBeNull();
})->with(['null' => [null], 'an empty string' => ['']]);

it('AC-10: passes a custom scope the request being resolved', function (): void {
    $received = null;
    DynamicThrottle::scope('plan', function (Request $request) use (&$received): string {
        $received = $request;

        return 'gold';
    });
    $request = Identities::request();

    Identities::of($request, Identities::rule('custom', parameter: 'plan'));

    expect($received)->toBe($request);
});

it('AC-11: resolves a ScopeResolver class from the container, once for each request', function (): void {
    PlanFromConfig::$instances = 0;
    config()->set('test.plan', 'gold');
    DynamicThrottle::scope('plan', PlanFromConfig::class);
    $rule = Identities::rule('custom', parameter: 'plan');

    $fromClass = [Identities::of(Identities::request(), $rule), Identities::of(Identities::request(), $rule)];
    DynamicThrottle::scope('plan', fn (): string => 'gold');

    expect($fromClass[0])->toBe($fromClass[1])->toBe(Identities::of(Identities::request(), $rule))
        ->and(PlanFromConfig::$instances)->toBe(2);
});

it('AC-11: takes a ScopeResolver instance, and replaces a scope registered again', function (): void {
    $rule = Identities::rule('custom', parameter: 'plan');
    config()->set('test.plan', 'gold');
    DynamicThrottle::scope('plan', new PlanFromConfig(config()));
    $gold = Identities::of(Identities::request(), $rule);
    DynamicThrottle::scope('plan', fn (): string => 'silver');
    $replaced = Identities::of(Identities::request(), $rule);
    DynamicThrottle::scope('tier', fn (): string => 'silver');

    expect($replaced)->not->toBe($gold)
        ->toBe(Identities::of(Identities::request(), Identities::rule('custom', parameter: 'tier')));
});

it('AC-11: accepts a name of 64 characters from a-z, 0-9, _ and -', function (): void {
    $name = str_repeat('a', 58).'_0-9zz';
    DynamicThrottle::scope($name, fn (): string => 'gold');

    expect(Identities::of(Identities::request(), Identities::rule('custom', parameter: $name)))->toMatch('/\A[0-9a-f]{64}\z/');
});

it('AC-11: rejects a name that is not 1 to 64 characters from a-z, 0-9, _ and -', function (string $name): void {
    expect(fn () => DynamicThrottle::scope($name, fn (): string => 'gold'))->toThrow(InvalidArgumentException::class);
})->with([
    'upper case' => ['Plan'],
    'empty' => [''],
    '65 characters' => [str_repeat('a', 65)],
    'a space' => ['plan tier'],
    'a dot' => ['plan.tier'],
]);

it('AC-11: rejects a class that is not a ScopeResolver', function (string $class): void {
    expect(fn () => DynamicThrottle::scope('plan', $class))->toThrow(InvalidArgumentException::class);
})->with([
    'another class' => [stdClass::class],
    'a class that does not exist' => ['App\Scopes\MissingPlan'],
]);

it('AC-12: ends evaluation with a Failed outcome for a rule whose identity cannot be resolved', function (string $failure, string $scope, ?string $cause): void {
    $failing = $failure === 'undefined guard'
        ? Identities::rule('user', id: 'b')
        : Identities::rule('custom', parameter: 'plan', id: 'b');
    match ($failure) {
        'unregistered' => null,
        'throws' => DynamicThrottle::scope('plan', fn (): never => throw new RuntimeException('The plan service is down.')),
        'returns an array' => DynamicThrottle::scope('plan', fn (): array => ['x']),
        'returns an object' => DynamicThrottle::scope('plan', fn (): stdClass => new stdClass),
        'returns true' => DynamicThrottle::scope('plan', fn (): bool => true),
        'undefined guard' => config()->set('dynamic-throttle.scopes.user.guards', ['nope']),
    };

    $evaluation = (new RuleEvaluator(Identities::store()))
        ->evaluate([Identities::rule('ip', id: 'a'), $failing], Identities::resolver(Identities::request()));

    expect($evaluation->outcome)->toBe(EvaluationOutcome::Failed)
        ->and($evaluation->failure?->rule->id)->toBe('b')
        ->and($evaluation->failure?->exception)->toBeInstanceOf(IdentityUnavailableException::class)
        ->and($evaluation->failure?->exception->getMessage())->toContain($scope)
        ->and($evaluation->failure?->exception->getPrevious()?->getMessage())->toBe($cause)
        ->and(array_map(fn (RuleAttempt $attempt): array => [$attempt->rule->id, $attempt->released, $attempt->attempt->remaining], $evaluation->attempts))
        ->toBe([['a', false, 59]]);
})->with([
    'an unregistered custom scope' => ['unregistered', 'custom:plan', null],
    'a resolver that throws' => ['throws', 'custom:plan', 'The plan service is down.'],
    'a resolver that returns an array' => ['returns an array', 'custom:plan', null],
    'a resolver that returns an object' => ['returns an object', 'custom:plan', null],
    'a resolver that returns true' => ['returns true', 'custom:plan', null],
    'an undefined guard' => ['undefined guard', 'user', 'Auth guard [nope] is not defined.'],
]);

it('AC-13: resolves each scope once per request, however many rules use it', function (): void {
    $calls = 0;
    DynamicThrottle::scope('plan', function (Request $request) use (&$calls): ?string {
        $calls++;

        return $request->headers->get('X-Plan');
    });
    $rules = [
        Identities::rule('custom', parameter: 'plan', id: 'p1'),
        Identities::rule('custom', parameter: 'plan', id: 'p2'),
        Identities::rule('custom', parameter: 'plan', id: 'p3'),
    ];

    $gold = Identities::resolver(Identities::request('203.0.113.7', ['X-Plan' => 'gold']));
    $goldIdentities = array_map($gold->resolve(...), $rules);
    $callsForOneRequest = $calls;
    $silver = Identities::resolver(Identities::request('203.0.113.7', ['X-Plan' => 'silver']));
    $silverIdentities = array_map($silver->resolve(...), $rules);

    expect([$callsForOneRequest, $calls])->toBe([1, 2])
        ->and(array_unique($goldIdentities))->toHaveCount(1)
        ->and(array_unique($silverIdentities))->toHaveCount(1)
        ->and($silverIdentities[0])->not->toBe($goldIdentities[0]);
});
