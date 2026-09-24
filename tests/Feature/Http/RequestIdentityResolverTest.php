<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Stacking\EvaluationOutcome;
use Afzal\DynamicThrottle\Engine\Stacking\RuleAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Engine\Stacking\RuleFailure;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestAdmin;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\Identities;
use Afzal\DynamicThrottle\Tests\Support\PlanFromConfig;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Exception\ConflictingHeadersException;

/*
| SPEC-008 AC-1 to AC-13: the identities one request counts under, through the
| container's resolver, with the package's config and Laravel's own auth.
*/

beforeEach(function (): void {
    // Hashing is keyed, as in any Laravel app (ADR-0024).
    config()->set('app.key', 'base64:3u4DMqUd5sEWtS4w9UqJ7Qw/qMCRnGFhqRuSN40wNJA=');
});

afterEach(function (): void {
    // Trusted proxies are static on Symfony's Request: back to its defaults.
    Request::setTrustedProxies([], -1);
});

/**
 * A request whose IP Symfony cannot read: the app trusts the proxy for both
 * Forwarded and X-Forwarded-For, and the two disagree. Symfony throws only on
 * the first read, and answers 0.0.0.0 after that.
 */
function conflictingForwardedRequest(string $forwarded = '198.51.100.1', string $forwardedFor = '198.51.100.2'): Request
{
    Request::setTrustedProxies(['203.0.113.7'], Request::HEADER_FORWARDED | Request::HEADER_X_FORWARDED_FOR);

    return Identities::request('203.0.113.7', ['Forwarded' => "for={$forwarded}", 'X-Forwarded-For' => $forwardedFor]);
}

/**
 * @param  list<RuleFailure>|list<RuleAttempt>  $items
 * @return list<string>
 */
function identityRuleIds(array $items): array
{
    return array_map(fn (RuleFailure|RuleAttempt $item): string => $item->rule->id, $items);
}

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

it('AC-2: counts an address in the well-known NAT64 prefix as its IPv4 address (decision 8)', function (): void {
    $rule = Identities::rule('ip');

    expect(Identities::of(Identities::request('64:ff9b::203.0.113.7'), $rule))
        ->toBe(Identities::of(Identities::request('203.0.113.7'), $rule))
        ->not->toBe(Identities::of(Identities::request('64:ff9b::203.0.113.8'), $rule));
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

    $beforeAuth = Identities::of(Identities::request('203.0.113.7'), $rule);
    $lookupsBeforeAuth = $lookups;
    Auth::guard()->user(); // What auth middleware does.
    $afterAuth = Identities::of(Identities::request('203.0.113.7'), $rule);

    expect([$beforeAuth, $afterAuth])->toBe([$guest, $user42])
        ->and([$lookupsBeforeAuth, $lookups])->toBe([0, 1]);
});

it('AC-5: runs no query of its own when the session holds a user that auth has not loaded yet', function (): void {
    config()->set('session.driver', 'array');
    Schema::create('scope_users', function (Blueprint $table): void {
        $table->id();
        $table->string('password')->nullable();
        $table->string('remember_token')->nullable();
    });
    DB::table('scope_users')->insert(['id' => 42]);
    config()->set('auth.providers.scope_users', ['driver' => 'database', 'table' => 'scope_users']);
    config()->set('auth.guards.web', ['driver' => 'session', 'provider' => 'scope_users']);
    app('session.store')->put(Auth::guard('web')->getName(), 42);
    $rule = Identities::rule('user');
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $beforeAuth = Identities::of(Identities::request('203.0.113.7'), $rule);
    $queriesBeforeAuth = $queries;
    $user = Auth::guard()->user(); // What auth middleware does: one query.
    $afterAuth = Identities::of(Identities::request('203.0.113.7'), $rule);
    $queriesAfterAuth = $queries;
    $this->actingAs(new GenericUser(['id' => 42]));

    expect([$queriesBeforeAuth, $queriesAfterAuth])->toBe([0, 1])
        ->and($beforeAuth)->toBe(Identities::hashed('guest:203.0.113.7'))
        ->and($user?->getAuthIdentifier())->toBe(42)
        ->and($afterAuth)->toBe(Identities::of(Identities::request('203.0.113.7'), $rule));
});

it('AC-6: asks each guard in scopes.user.guards in order, and the first user found wins', function (): void {
    $asked = [];
    Auth::viaRequest('nobody', function () use (&$asked): ?TestUser {
        $asked[] = 'nobody';

        return null;
    });
    Auth::viaRequest('first', function () use (&$asked): TestUser {
        $asked[] = 'first';

        return new TestUser(['id' => 1]);
    });
    Auth::viaRequest('second', function () use (&$asked): TestUser {
        $asked[] = 'second';

        return new TestUser(['id' => 2]);
    });
    config()->set('auth.guards.none', ['driver' => 'nobody']);
    config()->set('auth.guards.api', ['driver' => 'first']);
    config()->set('auth.guards.web', ['driver' => 'second']);
    config()->set('dynamic-throttle.scopes.user.guards', ['none', 'api', 'web']);
    $rule = Identities::rule('user');

    $listed = Identities::of(Identities::request('203.0.113.7'), $rule);
    $askedWhenListed = $asked;
    config()->set('dynamic-throttle.scopes.user.guards');
    $this->actingAs(new TestUser(['id' => 1]));

    expect($askedWhenListed)->toBe(['nobody', 'first'])
        ->and($listed)->toBe(Identities::of(Identities::request('203.0.113.7'), $rule))
        ->not->toBe(Identities::hashed('guest:203.0.113.7'));
});

it('AC-7: counts a bearer token as one client from any IP', function (): void {
    $rule = Identities::rule('api_token');
    $token = fn (string $ip, string $token): ?string => Identities::of(Identities::request($ip, ['Authorization' => "Bearer {$token}"]), $rule);

    expect($token('203.0.113.7', 'abc'))->toBe($token('198.51.100.1', 'abc'))
        ->not->toBe($token('203.0.113.7', 'abd'));
});

it('AC-7: counts one bearer token as one client however the header spaces it', function (string $authorization): void {
    $rule = Identities::rule('api_token');

    expect(Identities::of(Identities::request('203.0.113.7', ['Authorization' => $authorization]), $rule))
        ->toBe(Identities::of(Identities::request('203.0.113.7', ['Authorization' => 'Bearer abc']), $rule));
})->with([
    // RFC 9110 allows several spaces after the scheme, and Passport and jwt-auth accept all of these.
    'two spaces' => ['Bearer  abc'],
    'a tab' => ["Bearer \tabc"],
    'a trailing space' => ['Bearer abc '],
    'a space before a comma' => ['Bearer abc ,x'],
]);

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

it('AC-12: fails only the rule whose identity cannot be resolved, and the rules around it still count', function (string $failure, string $scope, ?string $causeClass, string $cause): void {
    $failing = match ($failure) {
        'undefined guard', 'unreadable user id', 'user id without a string form' => Identities::rule('user', id: 'b'),
        'unreadable client IP' => Identities::rule('ip', id: 'b'),
        default => Identities::rule('custom', parameter: 'plan', id: 'b'),
    };
    $unformattable = fn (string $what): Stringable => new readonly class($what) implements Stringable
    {
        public function __construct(private string $what) {}

        public function __toString(): string
        {
            throw new RuntimeException("The {$this->what} cannot be formatted.");
        }
    };
    match ($failure) {
        'unregistered', 'unreadable client IP' => null,
        'throws' => DynamicThrottle::scope('plan', fn (): never => throw new RuntimeException('The plan service is down.')),
        'returns an array' => DynamicThrottle::scope('plan', fn (): array => ['x']),
        'returns an object' => DynamicThrottle::scope('plan', fn (): stdClass => new stdClass),
        'returns true' => DynamicThrottle::scope('plan', fn (): bool => true),
        'custom value without a string form' => DynamicThrottle::scope('plan', fn (): Stringable => $unformattable('plan')),
        'undefined guard' => config()->set('dynamic-throttle.scopes.user.guards', ['nope']),
        'unreadable user id' => $this->actingAs(new class(['id' => 1]) extends GenericUser
        {
            public function getAuthIdentifier(): mixed
            {
                throw new RuntimeException('The id column is missing.');
            }
        }),
        'user id without a string form' => $this->actingAs(new class(['id' => $unformattable('id')]) extends GenericUser {}),
    };
    $request = $failure === 'unreadable client IP' ? conflictingForwardedRequest() : Identities::request();

    // Rules a and c are global, which never read the client IP.
    $evaluation = (new RuleEvaluator(Identities::store()))
        ->evaluate([Identities::rule('global', id: 'a'), $failing, Identities::rule('global', id: 'c')], Identities::resolver($request));
    $failure = $evaluation->failures[0] ?? null;
    $previous = $failure?->exception->getPrevious();

    expect($evaluation->outcome)->toBe(EvaluationOutcome::Failed)
        ->and(array_map(fn (RuleFailure $failure): string => $failure->rule->id, $evaluation->failures))->toBe(['b'])
        ->and($failure?->exception)->toBeInstanceOf(IdentityUnavailableException::class)
        ->and($failure?->exception->getMessage())->toStartWith("The identity for {$scope} could not be resolved")
        ->and($previous instanceof Throwable ? $previous::class : null)->toBe($causeClass)
        ->and($previous?->getMessage() ?? '')->toContain($cause)
        ->and(array_map(fn (RuleAttempt $attempt): array => [$attempt->rule->id, $attempt->released, $attempt->attempt->remaining], $evaluation->attempts))
        ->toBe([['a', false, 59], ['c', false, 59]]);
})->with([
    'an unregistered custom scope' => ['unregistered', 'custom:plan', null, ''],
    'a resolver that throws' => ['throws', 'custom:plan', RuntimeException::class, 'The plan service is down.'],
    'a resolver that returns an array' => ['returns an array', 'custom:plan', null, ''],
    'a resolver that returns an object' => ['returns an object', 'custom:plan', null, ''],
    'a resolver that returns true' => ['returns true', 'custom:plan', null, ''],
    'a custom value without a string form' => ['custom value without a string form', 'custom:plan', RuntimeException::class, 'The plan cannot be formatted.'],
    'an undefined guard' => ['undefined guard', 'user', InvalidArgumentException::class, 'Auth guard [nope] is not defined.'],
    'a user id that cannot be read' => ['unreadable user id', 'user', RuntimeException::class, 'The id column is missing.'],
    'a user id without a string form' => ['user id without a string form', 'user', RuntimeException::class, 'The id cannot be formatted.'],
    'a client IP that cannot be read' => ['unreadable client IP', 'ip', ConflictingHeadersException::class, 'conflicting'],
]);

it('AC-12: reads the client IP only for a rule that needs it', function (): void {
    $resolver = Identities::resolver(conflictingForwardedRequest());
    $this->actingAs(new TestUser(['id' => 42]));

    expect($resolver->resolve(Identities::rule('global')))->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($resolver->resolve(Identities::rule('user')))->toMatch('/\A[0-9a-f]{64}\z/')
        ->and(fn (): ?string => $resolver->resolve(Identities::rule('ip')))
        ->toThrow(IdentityUnavailableException::class, 'The identity for ip could not be resolved');
});

it('AC-12: fails every rule that needs an unreadable client IP, not only the first, with the same cause', function (): void {
    // b is an ip rule, and c a user rule that falls back to the IP for a guest.
    $rules = [Identities::rule('global', id: 'a'), Identities::rule('ip', id: 'b'), Identities::rule('user', id: 'c'), Identities::rule('global', id: 'd')];

    $evaluation = (new RuleEvaluator(Identities::store()))->evaluate($rules, Identities::resolver(conflictingForwardedRequest()));
    $ip = $evaluation->failures[0] ?? null;
    $guest = $evaluation->failures[1] ?? null;

    expect(identityRuleIds($evaluation->failures))->toBe(['b', 'c'])
        ->and(identityRuleIds($evaluation->attempts))->toBe(['a', 'd'])
        ->and($ip?->exception->getMessage())->toStartWith('The identity for ip could not be resolved: the client IP could not be read')
        ->and($guest?->exception->getMessage())->toStartWith('The identity for user could not be resolved: the client IP could not be read')
        ->and($ip?->exception->getPrevious())->toBeInstanceOf(ConflictingHeadersException::class)
        ->and($guest?->exception->getPrevious())->toBe($ip?->exception->getPrevious());
});

it('AC-12: never counts two clients whose IP cannot be read under one identity', function (): void {
    $evaluator = new RuleEvaluator(Identities::store());
    $rules = [Identities::rule('ip', id: 'a'), Identities::rule('ip', id: 'b', max: 1)];

    $first = $evaluator->evaluate($rules, Identities::resolver(conflictingForwardedRequest('198.51.100.1', '198.51.100.2')));
    $second = $evaluator->evaluate($rules, Identities::resolver(conflictingForwardedRequest('192.0.2.1', '192.0.2.2')));

    expect([$first->outcome, $second->outcome])->toBe([EvaluationOutcome::Failed, EvaluationOutcome::Failed])
        ->and(identityRuleIds($second->failures))->toBe(['a', 'b'])
        ->and($second->attempts)->toBe([]);
});

it('AC-12: reads the client IP from a copy of the request, so the request\'s own ip() still throws', function (): void {
    $request = conflictingForwardedRequest();

    $evaluation = (new RuleEvaluator(Identities::store()))->evaluate([Identities::rule('ip')], Identities::resolver($request));

    expect($evaluation->outcome)->toBe(EvaluationOutcome::Failed)
        ->and(fn (): ?string => $request->ip())->toThrow(ConflictingHeadersException::class);
});

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

it('AC-13: asks a scope that fails once per request, and fails every rule that uses it with the same exception', function (string $scope): void {
    $calls = 0;
    $failing = function () use (&$calls): never {
        $calls++;

        throw new RuntimeException('The service is down.');
    };
    if ($scope === 'custom') {
        DynamicThrottle::scope('plan', $failing);
    } else {
        Auth::viaRequest('failing', $failing);
        config()->set('auth.guards.failing', ['driver' => 'failing']);
        config()->set('dynamic-throttle.scopes.user.guards', ['failing']);
    }
    // A parameter left on a user rule, which takes none, must not make the guard asked again.
    $rules = array_map(
        fn (string $id, ?string $parameter): CompiledRule => Identities::rule($scope, parameter: $parameter, id: $id),
        ['p1', 'p2', 'p3'],
        $scope === 'custom' ? ['plan', 'plan', 'plan'] : [null, 'x', 'web'],
    );

    $evaluation = (new RuleEvaluator(Identities::store()))->evaluate($rules, Identities::resolver(Identities::request()));
    $exceptions = array_map(fn (RuleFailure $failure): Throwable => $failure->exception, $evaluation->failures);

    expect($calls)->toBe(1)
        ->and(array_map(fn (RuleFailure $failure): string => $failure->rule->id, $evaluation->failures))->toBe(['p1', 'p2', 'p3'])
        ->and($exceptions[1])->toBe($exceptions[0])
        ->and($exceptions[2])->toBe($exceptions[0])
        ->and($exceptions[0]->getPrevious()?->getMessage())->toBe('The service is down.');
})->with(['a custom resolver that throws' => ['custom'], 'a guard that throws' => ['user']]);
