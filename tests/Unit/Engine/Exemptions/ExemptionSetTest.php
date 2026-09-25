<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\ExemptionSubjects;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Exemptions\ExemptionSet;
use Afzal\DynamicThrottle\Manifest\CompiledExemption;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;

covers(ExemptionSet::class);

/*
| SPEC-009 AC-13 to AC-17 and AC-20, the pure parts: which exemption, if any,
| frees one request from one rule. The request is a fake that records what the
| set asks it, and user and custom values arrive already hashed.
*/

const EXEMPTION_NOW = FakeClock::T0 * 1_000_000;

/**
 * Exemptions with the ids 1, 2, 3... in the order given, as the compiler orders them.
 *
 * @param  list<array<string, mixed>>  $exemptions  overrides of ManifestFixtures::exemption()
 */
function exemptionSet(array $exemptions): ExemptionSet
{
    return new ExemptionSet(array_map(
        fn (array $overrides, int $position): CompiledExemption => CompiledExemption::fromArray(ManifestFixtures::exemption(['id' => (string) ($position + 1), ...$overrides])),
        $exemptions,
        array_keys($exemptions),
    ));
}

/**
 * A value's hash, as the resolver and the compiler write it.
 */
function exemptionHash(string $value): string
{
    return hash('sha256', $value);
}

/**
 * One request, for each subject. It records what the set asks, in order.
 *
 * @param  array<string, string>  $custom  each custom scope's hashed value
 * @param  list<string>  $failing  the checks that throw: ip, user or custom:<scope>
 * @return ExemptionSubjects&object{asked: list<string>}
 */
function requestSubjects(?string $ip = null, ?string $user = null, array $custom = [], array $failing = []): ExemptionSubjects
{
    return new class($ip, $user, $custom, $failing) implements ExemptionSubjects
    {
        /** @var list<string> */
        public array $asked = [];

        /**
         * @param  array<string, string>  $custom
         * @param  list<string>  $failing
         */
        public function __construct(
            private readonly ?string $address,
            private readonly ?string $userHash,
            private readonly array $custom,
            private readonly array $failing,
        ) {}

        public function ip(): ?string
        {
            return $this->answer('ip', $this->address);
        }

        public function user(): ?string
        {
            return $this->answer('user', $this->userHash);
        }

        public function custom(string $scope): ?string
        {
            return $this->answer("custom:{$scope}", $this->custom[$scope] ?? null);
        }

        private function answer(string $check, ?string $value): ?string
        {
            $this->asked[] = $check;

            return in_array($check, $this->failing, true) ? throw IdentityUnavailableException::because("The {$check} check failed.") : $value;
        }
    };
}

it('AC-13: exempts the client an address names, and no other', function (): void {
    $set = exemptionSet([['match' => '203.0.113.7/32']]);

    expect($set->match('1', EXEMPTION_NOW, requestSubjects(ip: '203.0.113.7')))->toBe('1')
        ->and($set->match('1', EXEMPTION_NOW, requestSubjects(ip: '203.0.113.8')))->toBeNull();
});

it('AC-13: exempts every client in a range, and no client outside it', function (string $range, string $inside, string $outside): void {
    $set = exemptionSet([['match' => $range]]);

    expect($set->match('1', EXEMPTION_NOW, requestSubjects(ip: $inside)))->toBe('1')
        ->and($set->match('1', EXEMPTION_NOW, requestSubjects(ip: $outside)))->toBeNull();
})->with([
    'an IPv4 /24' => ['203.0.113.0/24', '203.0.113.200', '203.0.114.1'],
    'an IPv4 /8' => ['10.0.0.0/8', '10.255.255.255', '11.0.0.0'],
    'an IPv6 /32' => ['2001:db8::/32', '2001:db8:ffff::1', '2001:db9::1'],
    'an IPv6 address, taken whole before IPv6 grouping' => ['2001:db8:1:2::1/128', '2001:db8:1:2::1', '2001:db8:1:2::2'],
    'every IPv4 address, and no IPv6 one' => ['0.0.0.0/0', '198.51.100.1', '2001:db8::1'],
    'every IPv6 address, and no IPv4 one' => ['::/0', '2001:db8::1', '198.51.100.1'],
]);

it('AC-13: counts an IPv4 client that reaches the app as IPv6 as its IPv4 address', function (string $client): void {
    expect(exemptionSet([['match' => '203.0.113.0/24']])->match('1', EXEMPTION_NOW, requestSubjects(ip: $client)))->toBe('1');
})->with(['IPv4-mapped' => ['::ffff:203.0.113.9'], 'NAT64' => ['64:ff9b::203.0.113.9']]);

it('AC-13: never checks an address against a range of the other IP version', function (): void {
    // 32.1.13.184 is 0x20010db8: the same leading bits as 2001:db8::.
    $v4 = exemptionSet([['match' => '32.1.13.184/29']]);
    $v6 = exemptionSet([['match' => '2001:db8::/29']]);

    expect($v4->match('1', EXEMPTION_NOW, requestSubjects(ip: '2001:db8::1')))->toBeNull()
        ->and($v6->match('1', EXEMPTION_NOW, requestSubjects(ip: '32.1.13.185')))->toBeNull();
});

it('AC-13: matches no ip exemption for a request without an IP', function (): void {
    $set = exemptionSet([['match' => '0.0.0.0/0'], ['match' => '::/0']]);

    expect($set->match('1', EXEMPTION_NOW, requestSubjects()))->toBeNull();
});

it('AC-14 and AC-15: exempts the user or the custom value whose hash the exemption holds', function (): void {
    $set = exemptionSet([
        ['subject' => 'user', 'match' => exemptionHash('User:42')],
        ['subject' => 'custom', 'parameter' => 'tenant', 'match' => exemptionHash('acme')],
    ]);
    $check = fn (?string $user = null, array $custom = []): ?string => $set->match('1', EXEMPTION_NOW, requestSubjects(user: $user, custom: $custom));

    expect($check(exemptionHash('User:42')))->toBe('1')
        ->and($check(exemptionHash('User:43')))->toBeNull()
        ->and($check(custom: ['tenant' => exemptionHash('acme')]))->toBe('2')
        ->and($check(custom: ['tenant' => exemptionHash('globex')]))->toBeNull()
        ->and($check())->toBeNull();
});

it('AC-15: keeps custom scopes apart, and asks only the scope the exemption names', function (): void {
    $set = exemptionSet([['subject' => 'custom', 'parameter' => 'plan', 'match' => exemptionHash('acme')]]);
    $subjects = requestSubjects(custom: ['tenant' => exemptionHash('acme')]);

    expect($set->match('1', EXEMPTION_NOW, $subjects))->toBeNull()
        ->and($subjects->asked)->toBe(['custom:plan']);
});

it('AC-16: applies a global exemption to every rule, and a rule\'s exemption to that rule only', function (): void {
    $set = exemptionSet([['rule' => 'a', 'match' => '198.51.100.0/24'], ['rule' => null, 'match' => '203.0.113.0/24']]);

    expect($set->match('a', EXEMPTION_NOW, requestSubjects(ip: '198.51.100.1')))->toBe('1')
        ->and($set->match('b', EXEMPTION_NOW, requestSubjects(ip: '198.51.100.1')))->toBeNull()
        ->and($set->match('a', EXEMPTION_NOW, requestSubjects(ip: '203.0.113.1')))->toBe('2')
        ->and($set->match('b', EXEMPTION_NOW, requestSubjects(ip: '203.0.113.1')))->toBe('2');
});

it('AC-16: names the lowest id when several exemptions of one subject match, the rule\'s or global ones', function (array $exemptions, string $ip): void {
    expect(exemptionSet($exemptions)->match('a', EXEMPTION_NOW, requestSubjects(ip: $ip)))->toBe('1');
})->with([
    'a global range, then the rule\'s address' => [[['rule' => null, 'match' => '203.0.0.0/8'], ['rule' => 'a', 'match' => '203.0.113.7/32']], '203.0.113.7'],
    'the rule\'s address, then a global range' => [[['rule' => 'a', 'match' => '203.0.113.7/32'], ['rule' => null, 'match' => '203.0.0.0/8']], '203.0.113.7'],
    'three ranges of the rule' => [[['rule' => 'a', 'match' => '203.0.113.0/24'], ['rule' => 'a', 'match' => '203.0.113.7/32'], ['rule' => 'a', 'match' => '203.0.0.0/8']], '203.0.113.7'],
    'two IPv6 ranges' => [[['rule' => null, 'match' => '2001:db8::/32'], ['rule' => 'a', 'match' => '2001:db8::/48']], '2001:db8::1'],
]);

it('AC-16: names the lowest id among users and custom values too', function (): void {
    $set = exemptionSet([
        ['rule' => null, 'subject' => 'user', 'match' => exemptionHash('User:42')],
        ['rule' => 'a', 'subject' => 'user', 'match' => exemptionHash('User:42')],
        ['rule' => 'a', 'subject' => 'custom', 'parameter' => 'plan', 'match' => exemptionHash('gold')],
        ['rule' => null, 'subject' => 'custom', 'parameter' => 'plan', 'match' => exemptionHash('gold')],
    ]);

    expect($set->match('a', EXEMPTION_NOW, requestSubjects(user: exemptionHash('User:42'))))->toBe('1')
        ->and($set->match('a', EXEMPTION_NOW, requestSubjects(custom: ['plan' => exemptionHash('gold')])))->toBe('3');
});

it('AC-16: checks the IP, then the user, then custom scopes by name, and stops at the first match', function (): void {
    $set = exemptionSet([
        ['subject' => 'custom', 'parameter' => 'tenant', 'match' => exemptionHash('acme')],
        ['subject' => 'custom', 'parameter' => 'plan', 'match' => exemptionHash('gold')],
        ['subject' => 'user', 'match' => exemptionHash('User:42')],
        ['match' => '203.0.113.0/24'],
    ]);
    $custom = ['tenant' => exemptionHash('acme'), 'plan' => exemptionHash('gold')];
    $everything = requestSubjects(ip: '203.0.113.7', user: exemptionHash('User:42'), custom: $custom);
    $userAndCustom = requestSubjects(ip: '198.51.100.1', user: exemptionHash('User:42'), custom: $custom);
    $customOnly = requestSubjects(ip: '198.51.100.1', custom: $custom);
    $nothing = requestSubjects(ip: '198.51.100.1');

    expect([$set->match('1', EXEMPTION_NOW, $everything), $everything->asked])->toBe(['4', ['ip']])
        ->and([$set->match('1', EXEMPTION_NOW, $userAndCustom), $userAndCustom->asked])->toBe(['3', ['ip', 'user']])
        ->and([$set->match('1', EXEMPTION_NOW, $customOnly), $customOnly->asked])->toBe(['2', ['ip', 'user', 'custom:plan']])
        ->and([$set->match('1', EXEMPTION_NOW, $nothing), $nothing->asked])->toBe([null, ['ip', 'user', 'custom:plan', 'custom:tenant']]);
});

it('asks only what the rule\'s and the global exemptions need, each once', function (): void {
    $set = exemptionSet([
        ['rule' => 'a', 'subject' => 'user', 'match' => exemptionHash('User:42')],
        ['rule' => 'a', 'subject' => 'custom', 'parameter' => 'plan', 'match' => exemptionHash('gold')],
        ['rule' => 'a', 'subject' => 'custom', 'parameter' => 'plan', 'match' => exemptionHash('silver')],
        ['rule' => 'b', 'subject' => 'custom', 'parameter' => 'tenant', 'match' => exemptionHash('acme')],
        ['rule' => null, 'match' => '203.0.113.0/24'],
    ]);
    $forA = requestSubjects(ip: '198.51.100.1');
    $forC = requestSubjects(ip: '198.51.100.1');
    $forEmptySet = requestSubjects(ip: '198.51.100.1');

    $set->match('a', EXEMPTION_NOW, $forA);
    $set->match('c', EXEMPTION_NOW, $forC);
    exemptionSet([])->match('a', EXEMPTION_NOW, $forEmptySet);

    expect([$forA->asked, $forC->asked, $forEmptySet->asked])->toBe([['ip', 'user', 'custom:plan'], ['ip'], []]);
});

it('AC-17: ignores an exemption from the second it expires, with no new compile', function (): void {
    $set = exemptionSet([['match' => '203.0.113.7/32', 'expires_at' => FakeClock::T0 + 60]]);
    $at = fn (int $microseconds): ?string => $set->match('1', $microseconds, requestSubjects(ip: '203.0.113.7'));

    expect($at((FakeClock::T0 + 59) * 1_000_000))->toBe('1')
        ->and($at((FakeClock::T0 + 60) * 1_000_000 - 1))->toBe('1')
        ->and($at((FakeClock::T0 + 60) * 1_000_000))->toBeNull();
});

it('AC-17: lets an exemption that is still active match when a lower id has expired', function (): void {
    $set = exemptionSet([
        ['match' => '203.0.113.7/32', 'expires_at' => FakeClock::T0],
        ['match' => '203.0.113.0/24'],
        ['subject' => 'user', 'match' => exemptionHash('User:42'), 'expires_at' => FakeClock::T0],
        ['subject' => 'user', 'match' => exemptionHash('User:42'), 'expires_at' => FakeClock::T0 + 1],
    ]);

    expect($set->match('1', EXEMPTION_NOW, requestSubjects(ip: '203.0.113.7')))->toBe('2')
        ->and($set->match('1', EXEMPTION_NOW, requestSubjects(ip: '198.51.100.1', user: exemptionHash('User:42'))))->toBe('4');
});

it('AC-20: counts a failed check as no match, so a later check can still exempt the client', function (): void {
    $set = exemptionSet([['match' => '203.0.113.0/24'], ['subject' => 'custom', 'parameter' => 'plan', 'match' => exemptionHash('gold')]]);
    $subjects = requestSubjects(custom: ['plan' => exemptionHash('gold')], failing: ['ip']);

    expect($set->match('1', EXEMPTION_NOW, $subjects))->toBe('2')
        ->and($subjects->asked)->toBe(['ip', 'custom:plan']);
});

it('AC-20: throws the first failed check\'s exception when no check matched', function (): void {
    $set = exemptionSet([
        ['match' => '203.0.113.0/24'],
        ['subject' => 'user', 'match' => exemptionHash('User:42')],
        ['subject' => 'custom', 'parameter' => 'plan', 'match' => exemptionHash('gold')],
    ]);
    $subjects = requestSubjects(user: exemptionHash('User:43'), failing: ['ip', 'custom:plan']);

    expect(fn (): ?string => $set->match('1', EXEMPTION_NOW, $subjects))->toThrow(IdentityUnavailableException::class, 'The ip check failed.')
        ->and($subjects->asked)->toBe(['ip', 'user', 'custom:plan']);
});

it('lists the exemptions it was built from', function (): void {
    $exemptions = [
        CompiledExemption::fromArray(ManifestFixtures::exemption(['id' => '1'])),
        CompiledExemption::fromArray(ManifestFixtures::exemption(['id' => '2', 'subject' => 'user', 'match' => exemptionHash('User:42')])),
    ];

    expect((new ExemptionSet($exemptions))->all())->toBe($exemptions)
        ->and((new ExemptionSet)->all())->toBe([]);
});
