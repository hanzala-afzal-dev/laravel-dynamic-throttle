<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Compiler\ManifestCompiler;
use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Stacking\Evaluation;
use Afzal\DynamicThrottle\Engine\Stacking\EvaluationOutcome;
use Afzal\DynamicThrottle\Engine\Stacking\RuleAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Engine\Stacking\RuleExemption;
use Afzal\DynamicThrottle\Engine\Stacking\RuleFailure;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestAdmin;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\Exemptions;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Identities;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Exception\ConflictingHeadersException;

/*
| SPEC-009 AC-13 to AC-17, AC-20 and AC-22: exemptions along their whole path.
| Rows are saved, compiled into the manifest, and read back as a request reads
| them, then checked by that request's resolver. The clock stands at T0.
*/

beforeEach(function (): void {
    // Hashing is keyed, as in any Laravel app (ADR-0024).
    config()->set('app.key', 'base64:3u4DMqUd5sEWtS4w9UqJ7Qw/qMCRnGFhqRuSN40wNJA=');
    ManifestRepository::flushState();
    RuleSchema::migrate();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
});

afterEach(function (): void {
    ManifestRepository::flushState();
    // Trusted proxies are static on Symfony's Request: back to its defaults.
    Request::setTrustedProxies([], -1);
});

/**
 * The id of the exemption that frees the request from the rule, with the manifest as compiled.
 */
function exemptionFor(Manifest $manifest, Request $request, ?Rule $rule = null): ?string
{
    $compiled = $rule instanceof Rule ? Exemptions::compiled($manifest, $rule) : $manifest->rule(0);

    return Identities::resolver($request, $manifest->exemptions)->exemption($compiled);
}

/**
 * @return list<string>
 */
function exemptedIds(Evaluation $evaluation): array
{
    return array_map(fn (RuleExemption $exemption): string => $exemption->exemptionId, $evaluation->exempted);
}

it('exempts nothing when the resolver is given no exemptions', function (): void {
    expect(Identities::resolver(Identities::request())->exemption(Identities::rule('ip')))->toBeNull();
});

it('AC-13: exempts the client an address names, and no other', function (): void {
    Exemptions::rule();
    $exemption = Exemption::forIp('203.0.113.7');
    $exemption->save();
    $manifest = Exemptions::manifest();

    expect(exemptionFor($manifest, Identities::request('203.0.113.7')))->toBe((string) $exemption->id)
        ->and(exemptionFor($manifest, Identities::request('203.0.113.8')))->toBeNull();
});

it('AC-13: exempts the clients a range or an address covers, and no client outside it', function (string $value, string $inside, string $outside): void {
    Exemptions::rule();
    $exemption = Exemption::forIp($value);
    $exemption->save();
    $manifest = Exemptions::manifest();

    expect(exemptionFor($manifest, Identities::request($inside)))->toBe((string) $exemption->id)
        ->and(exemptionFor($manifest, Identities::request($outside)))->toBeNull();
})->with([
    'an IPv4 range' => ['203.0.113.0/24', '203.0.113.200', '203.0.114.1'],
    'an IPv6 range' => ['2001:db8::/32', '2001:db8:ffff::1', '2001:db9::1'],
    'an IPv6 address, taken whole before IPv6 grouping' => ['2001:db8:1:2::1', '2001:db8:1:2::1', '2001:db8:1:2::2'],
    'an IPv4 range, for IPv4-mapped clients' => ['203.0.113.0/24', '::ffff:203.0.113.9', '::ffff:203.0.114.9'],
    'an IPv4 range, for NAT64 clients' => ['203.0.113.0/24', '64:ff9b::203.0.113.9', '64:ff9b::203.0.114.9'],
    'an IPv4-mapped address, for its IPv4 client' => ['::ffff:203.0.113.7', '203.0.113.7', '203.0.113.8'],
]);

it('AC-13: matches no ip exemption for a request without an IP', function (): void {
    Exemptions::rule();
    Exemption::forIp('0.0.0.0/0')->save();
    Exemption::forIp('::/0')->save();

    expect(exemptionFor(Exemptions::manifest(), Identities::request(null)))->toBeNull();
});

it('AC-13: checks the client IP the app\'s trusted proxies give (ADR-0009)', function (): void {
    Exemptions::rule();
    $exemption = Exemption::forIp('198.51.100.9');
    $exemption->save();
    $manifest = Exemptions::manifest();

    $untrusted = exemptionFor($manifest, Identities::request('203.0.113.7', ['X-Forwarded-For' => '198.51.100.9']));
    Request::setTrustedProxies(['203.0.113.7'], Request::HEADER_X_FORWARDED_FOR);
    $trusted = exemptionFor($manifest, Identities::request('203.0.113.7', ['X-Forwarded-For' => '198.51.100.9']));

    expect([$untrusted, $trusted])->toBe([null, (string) $exemption->id]);
});

it('AC-14: exempts the user it names, and not another user, a guest, or another user type with the same id', function (): void {
    Exemptions::rule();
    $exemption = Exemption::forUser(new TestUser(['id' => 42]));
    $exemption->save();
    $manifest = Exemptions::manifest();

    $guest = exemptionFor($manifest, Identities::request());
    $this->actingAs(new TestUser(['id' => 42]));
    $user42 = exemptionFor($manifest, Identities::request());
    $this->actingAs(new TestUser(['id' => 43]));
    $user43 = exemptionFor($manifest, Identities::request());
    $this->actingAs(new TestAdmin(['id' => 42]));
    $admin42 = exemptionFor($manifest, Identities::request());

    expect([$guest, $user42, $user43, $admin42])->toBe([null, (string) $exemption->id, null, null]);
});

it('AC-14: exempts nobody, and runs no query, while auth has not loaded the session\'s user', function (): void {
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
    Exemptions::rule();
    // The database user provider gives GenericUser models.
    $exemption = Exemption::forUser(new GenericUser(['id' => 42]));
    $exemption->save();
    $manifest = Exemptions::manifest();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $beforeAuth = exemptionFor($manifest, Identities::request());
    $queriesBeforeAuth = $queries;
    Auth::guard()->user(); // What auth middleware does: one query.
    $afterAuth = exemptionFor($manifest, Identities::request());

    expect([$beforeAuth, $afterAuth])->toBe([null, (string) $exemption->id])
        ->and([$queriesBeforeAuth, $queries])->toBe([0, 1]);
});

it('AC-15: exempts the custom value it names, exactly, and not another value or none', function (): void {
    DynamicThrottle::scope('tenant', fn (Request $request): ?string => $request->headers->get('X-Tenant'));
    Exemptions::rule();
    $exemption = Exemption::forCustom('tenant', 'acme');
    $exemption->save();
    $manifest = Exemptions::manifest();
    $check = fn (array $headers): ?string => exemptionFor($manifest, Identities::request('203.0.113.7', $headers));

    expect([$check(['X-Tenant' => 'acme']), $check(['X-Tenant' => 'globex']), $check(['X-Tenant' => 'ACME']), $check([])])
        ->toBe([(string) $exemption->id, null, null, null]);
});

it('AC-15: calls a custom resolver once per request, for three rules and two exemptions, and the rule that counts by it', function (): void {
    $calls = 0;
    DynamicThrottle::scope('tenant', function (Request $request) use (&$calls): ?string {
        $calls++;

        return $request->headers->get('X-Tenant');
    });
    $rules = [
        Exemptions::rule(),
        Exemptions::rule(),
        Exemptions::rule(['scope' => Scope::Custom, 'scope_parameter' => 'tenant']),
    ];
    Exemption::forCustom('tenant', 'acme')->forRule($rules[0])->save();
    Exemption::forCustom('tenant', 'globex')->save();
    $manifest = Exemptions::manifest();
    $resolver = Identities::resolver(Identities::request('203.0.113.7', ['X-Tenant' => 'initech']), $manifest->exemptions);

    $exempted = array_map(fn (CompiledRule $rule): ?string => $resolver->exemption($rule), $manifest->rules());
    $identity = $resolver->resolve(Exemptions::compiled($manifest, $rules[2]));

    expect($exempted)->toBe([null, null, null])
        ->and($identity)->toBe(Identities::hashed('custom:initech'))
        ->and($calls)->toBe(1);
});

it('AC-15: never matches one custom scope\'s exemption against another scope\'s value', function (): void {
    DynamicThrottle::scope('tenant', fn (): string => 'acme');
    DynamicThrottle::scope('plan', fn (): string => 'gold');
    Exemptions::rule();
    Exemption::forCustom('plan', 'acme')->save();

    expect(exemptionFor(Exemptions::manifest(), Identities::request()))->toBeNull();
});

it('AC-16: exempts from every rule with a global exemption, and from one rule with that rule\'s', function (): void {
    $a = Exemptions::rule();
    $b = Exemptions::rule();
    $global = Exemption::forIp('203.0.113.0/24');
    $global->save();
    $ofA = Exemption::forIp('198.51.100.0/24')->forRule($a);
    $ofA->save();
    $manifest = Exemptions::manifest();

    expect(exemptionFor($manifest, Identities::request('203.0.113.7'), $a))->toBe((string) $global->id)
        ->and(exemptionFor($manifest, Identities::request('203.0.113.7'), $b))->toBe((string) $global->id)
        ->and(exemptionFor($manifest, Identities::request('198.51.100.7'), $a))->toBe((string) $ofA->id)
        ->and(exemptionFor($manifest, Identities::request('198.51.100.7'), $b))->toBeNull();
});

it('AC-16: names the lowest id when the rule\'s and a global exemption both match', function (): void {
    $a = Exemptions::rule();
    $b = Exemptions::rule();
    $ofA = Exemption::forIp('203.0.113.7')->forRule($a);
    $ofA->save();
    $global = Exemption::forIp('203.0.113.0/24');
    $global->save();
    Exemption::forIp('203.0.113.7')->forRule($b)->save();
    $manifest = Exemptions::manifest();

    expect(exemptionFor($manifest, Identities::request('203.0.113.7'), $a))->toBe((string) $ofA->id)
        ->and(exemptionFor($manifest, Identities::request('203.0.113.7'), $b))->toBe((string) $global->id);
});

it('AC-16: never calls a custom resolver once an ip exemption has matched', function (): void {
    $calls = 0;
    DynamicThrottle::scope('plan', function () use (&$calls): string {
        $calls++;

        return 'gold';
    });
    Exemptions::rule();
    Exemption::forCustom('plan', 'gold')->save();
    $ip = Exemption::forIp('203.0.113.7');
    $ip->save();

    expect(exemptionFor(Exemptions::manifest(), Identities::request('203.0.113.7')))->toBe((string) $ip->id)
        ->and($calls)->toBe(0);
});

it('AC-16: checks custom scopes by name, so plan comes before tenant', function (): void {
    DynamicThrottle::scope('tenant', fn (): string => 'acme');
    DynamicThrottle::scope('plan', fn (): string => 'gold');
    Exemptions::rule();
    Exemption::forCustom('tenant', 'acme')->save();
    $plan = Exemption::forCustom('plan', 'gold');
    $plan->save();

    expect(exemptionFor(Exemptions::manifest(), Identities::request()))->toBe((string) $plan->id);
});

it('AC-17: exempts until the second the exemption expires, with no new compile', function (): void {
    Exemptions::rule();
    $exemption = Exemption::forIp('203.0.113.7')->until(Carbon::createFromTimestamp(FakeClock::T0 + 60));
    $exemption->save();
    $manifest = Exemptions::manifest();

    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 59));
    $before = exemptionFor($manifest, Identities::request('203.0.113.7'));
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 60));
    $after = exemptionFor($manifest, Identities::request('203.0.113.7'));

    expect([$before, $after])->toBe([(string) $exemption->id, null]);
});

it('AC-20: counts the request on a rule whose ip exemption cannot be checked, lists the failure, and throws nothing', function (): void {
    Exemptions::rule(['scope' => Scope::Global]);
    Exemption::forIp('203.0.113.0/24')->save();
    $manifest = Exemptions::manifest();

    $evaluation = (new RuleEvaluator(Identities::store()))->evaluate($manifest->rules(), Identities::resolver(Identities::unreadableIpRequest(), $manifest->exemptions));
    $failure = $evaluation->exemptionFailures[0] ?? null;

    expect($evaluation->outcome)->toBe(EvaluationOutcome::Allowed)
        ->and($evaluation->exempted)->toBe([])
        ->and(array_map(fn (RuleAttempt $attempt): string => $attempt->rule->id, $evaluation->attempts))->toBe([$manifest->rule(0)->id])
        ->and($evaluation->exemptionFailures)->toHaveCount(1)
        ->and($failure?->rule->id)->toBe($manifest->rule(0)->id)
        ->and($failure?->exception)->toBeInstanceOf(IdentityUnavailableException::class)
        ->and($failure?->exception->getPrevious())->toBeInstanceOf(ConflictingHeadersException::class);
});

it('AC-20: counts the request on a rule whose custom exemption cannot be checked, lists the failure, and throws nothing', function (): void {
    DynamicThrottle::scope('plan', fn (): never => throw new RuntimeException('The plan service is down.'));
    Exemptions::rule(['scope' => Scope::Global]);
    Exemption::forCustom('plan', 'gold')->save();
    $manifest = Exemptions::manifest();

    $evaluation = (new RuleEvaluator(Identities::store()))->evaluate($manifest->rules(), Identities::resolver(Identities::request(), $manifest->exemptions));
    $failure = $evaluation->exemptionFailures[0] ?? null;

    expect($evaluation->outcome)->toBe(EvaluationOutcome::Allowed)
        ->and([$evaluation->exempted, $evaluation->failures])->toBe([[], []])
        ->and($evaluation->attempts)->toHaveCount(1)
        ->and($evaluation->exemptionFailures)->toHaveCount(1)
        ->and($failure?->exception)->toBeInstanceOf(IdentityUnavailableException::class)
        ->and($failure?->exception->getPrevious()?->getMessage())->toBe('The plan service is down.');
});

it('AC-20: exempts the client when an ip exemption matches, and never asks the failing custom:plan resolver', function (): void {
    $calls = 0;
    DynamicThrottle::scope('plan', function () use (&$calls): never {
        $calls++;

        throw new RuntimeException('The plan service is down.');
    });
    Exemptions::rule(['scope' => Scope::Global]);
    $ip = Exemption::forIp('203.0.113.7');
    $ip->save();
    Exemption::forCustom('plan', 'gold')->save();
    $manifest = Exemptions::manifest();

    $evaluation = (new RuleEvaluator(Identities::store()))->evaluate($manifest->rules(), Identities::resolver(Identities::request('203.0.113.7'), $manifest->exemptions));

    expect(exemptedIds($evaluation))->toBe([(string) $ip->id])
        ->and([$evaluation->exemptionFailures, $evaluation->attempts, $calls])->toBe([[], [], 0]);
});

it('AC-20: exempts the client when a later check matches after the IP and custom:plan checks failed', function (): void {
    DynamicThrottle::scope('plan', fn (): never => throw new RuntimeException('The plan service is down.'));
    DynamicThrottle::scope('tenant', fn (): string => 'acme');
    Exemptions::rule(['scope' => Scope::Global]);
    Exemption::forIp('203.0.113.7')->save();
    Exemption::forCustom('plan', 'gold')->save();
    $tenant = Exemption::forCustom('tenant', 'acme');
    $tenant->save();
    $manifest = Exemptions::manifest();

    $evaluation = (new RuleEvaluator(Identities::store()))->evaluate($manifest->rules(), Identities::resolver(Identities::unreadableIpRequest(), $manifest->exemptions));

    expect(exemptedIds($evaluation))->toBe([(string) $tenant->id])
        ->and([$evaluation->exemptionFailures, $evaluation->attempts])->toBe([[], []]);
});

it('AC-20: lists one exception for every rule whose ip exemption the unreadable client IP stops', function (): void {
    Exemptions::rule(['scope' => Scope::Global]);
    Exemptions::rule(['scope' => Scope::Global]);
    Exemption::forIp('203.0.113.0/24')->save();
    $manifest = Exemptions::manifest();

    $evaluation = (new RuleEvaluator(Identities::store()))->evaluate($manifest->rules(), Identities::resolver(Identities::unreadableIpRequest(), $manifest->exemptions));
    $exceptions = array_map(fn (RuleFailure $failure): Throwable => $failure->exception, $evaluation->exemptionFailures);

    expect($exceptions)->toHaveCount(2)
        ->and($exceptions[1])->toBe($exceptions[0])
        ->and($exceptions[0]->getPrevious())->toBeInstanceOf(ConflictingHeadersException::class);
});

it('counts a client IP holding a NUL byte on its own, exempts it from nothing, and throws nothing', function (): void {
    Exemptions::rule();
    Exemption::forIp('203.0.113.7')->save();
    $manifest = Exemptions::manifest();

    $evaluation = (new RuleEvaluator(Identities::store()))->evaluate($manifest->rules(), Identities::resolver(Identities::request("203.0.113.7\0"), $manifest->exemptions));

    expect($evaluation->outcome)->toBe(EvaluationOutcome::Allowed)
        ->and([$evaluation->exempted, $evaluation->exemptionFailures, $evaluation->failures])->toBe([[], [], []])
        ->and($evaluation->attempts)->toHaveCount(1);
});

it('reads the clock once per request for exemptions, and not at all when there are none', function (): void {
    $clock = new class implements Clock
    {
        public int $reads = 0;

        public function microseconds(): int
        {
            $this->reads++;

            return FakeClock::T0 * 1_000_000;
        }
    };
    app()->instance(Clock::class, $clock);
    Exemptions::rule();
    Exemptions::rule();
    Exemptions::rule();
    $withoutExemptions = Exemptions::manifest();
    $resolver = Identities::resolver(Identities::request(), $withoutExemptions->exemptions);
    array_map($resolver->exemption(...), $withoutExemptions->rules());
    $readsWithout = $clock->reads;

    Exemption::forIp('198.51.100.0/24')->save();
    $withExemptions = Exemptions::manifest();
    $resolver = Identities::resolver(Identities::request(), $withExemptions->exemptions);
    $exempted = array_map($resolver->exemption(...), $withExemptions->rules());

    expect($exempted)->toBe([null, null, null])
        ->and([$readsWithout, $clock->reads])->toBe([0, 1]);
});

it('AC-22: checks a request against 100 exemptions from a warm manifest with no database query', function (): void {
    DynamicThrottle::scope('tenant', fn (): string => 'initech');
    $rule = Exemptions::rule();

    foreach (range(1, 50) as $n) {
        Exemptions::insert(['value' => "10.{$n}.0.0/16"]);
    }

    foreach (range(1, 30) as $n) {
        Exemptions::insert(['rule_id' => $rule->id, 'subject' => 'user', 'value' => TestUser::class.":{$n}"]);
    }

    foreach (range(1, 20) as $n) {
        Exemptions::insert(['subject' => 'custom', 'subject_parameter' => 'tenant', 'value' => "tenant-{$n}"]);
    }

    app(ManifestCompiler::class)->compile();
    $repository = app(ManifestRepository::class);
    $repository->current();
    $this->actingAs(new TestUser(['id' => 99]));
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $manifest = $repository->current();
    $evaluator = new RuleEvaluator(Identities::store());
    $counted = $evaluator->evaluate($manifest->rules(), Identities::resolver(Identities::request('203.0.113.7'), $manifest->exemptions));
    $exempt = $evaluator->evaluate($manifest->rules(), Identities::resolver(Identities::request('10.7.1.1'), $manifest->exemptions));

    expect($manifest->exemptions->all())->toHaveCount(100)
        ->and([$counted->exempted, count($counted->attempts)])->toBe([[], 1])
        ->and(exemptedIds($exempt))->toHaveCount(1)
        ->and($queries)->toBe(0);
});
