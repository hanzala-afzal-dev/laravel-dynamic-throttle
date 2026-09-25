<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Manifest\CompiledExemption;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;

covers(CompiledExemption::class);

/*
| SPEC-009 AC-8 and AC-12: one exemption as the manifest stores it. Decoding is
| as strict as for rules, because a manifest that decoded wrongly would switch
| the wrong limits off (SPEC-006 AC-16).
*/

it('AC-8: decodes and encodes each subject with exactly its fields, in order', function (array $overrides): void {
    $data = ManifestFixtures::exemption($overrides);
    $exemption = CompiledExemption::fromArray($data);

    expect($exemption->toArray())->toBe($data)
        ->and(array_keys($exemption->toArray()))->toBe(['id', 'rule', 'subject', 'parameter', 'match', 'expires_at'])
        ->and(CompiledExemption::fromArray(array_reverse($data, true)))->toEqual($exemption);
})->with([
    'a global IPv4 range' => [[]],
    'an IPv6 range of one rule, until a time' => [['id' => '7', 'rule' => 'a', 'match' => '2001:db8::/32', 'expires_at' => 1_800_000_060]],
    'a user' => [['subject' => 'user', 'match' => hash('sha256', 'User:42')]],
    'a custom value' => [['subject' => 'custom', 'parameter' => 'tenant', 'match' => hash('sha256', 'acme')]],
]);

it('AC-8: keeps each field in its property', function (): void {
    $exemption = CompiledExemption::fromArray(ManifestFixtures::exemption([
        'id' => '7', 'rule' => 'a', 'subject' => 'custom', 'parameter' => 'tenant', 'match' => hash('sha256', 'acme'), 'expires_at' => 1_800_000_060,
    ]));

    expect([$exemption->id, $exemption->rule, $exemption->subject, $exemption->parameter, $exemption->match, $exemption->expiresAt])
        ->toBe(['7', 'a', ExemptionSubject::Custom, 'tenant', hash('sha256', 'acme'), 1_800_000_060]);
});

it('AC-12: rejects an exemption without exactly its fields', function (array $data): void {
    expect(fn (): CompiledExemption => CompiledExemption::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with(function (): iterable {
    $exemption = ManifestFixtures::exemption();

    yield 'a missing field' => [array_diff_key($exemption, ['expires_at' => true])];
    yield 'an extra field' => [[...$exemption, 'reason' => 'Office']];
    yield 'a list of the values' => [array_values($exemption)];
    yield 'no fields' => [[]];
});

it('AC-12: names the field whose value is wrong', function (array $overrides, string $field): void {
    expect(fn (): CompiledExemption => CompiledExemption::fromArray(ManifestFixtures::exemption($overrides)))
        ->toThrow(function (InvalidArgumentException $exception) use ($field): void {
            expect($exception->getMessage())->toStartWith("{$field} ");
        });
})->with([
    'an integer id' => [['id' => 7], 'id'],
    'an id counters would reject' => [['id' => 'Exemption 7'], 'id'],
    'an empty id' => [['id' => ''], 'id'],
    'an integer rule' => [['rule' => 1], 'rule'],
    'a rule id counters would reject' => [['rule' => 'Rule 1'], 'rule'],
    'an unknown subject' => [['subject' => 'header'], 'subject'],
    'a subject that is not a string' => [['subject' => null], 'subject'],
    'a custom exemption without a scope' => [['subject' => 'custom', 'match' => hash('sha256', 'acme')], 'parameter'],
    'a custom exemption with a name that is not a scope name' => [['subject' => 'custom', 'parameter' => 'Tenant!', 'match' => hash('sha256', 'acme')], 'parameter'],
    'an ip exemption with a parameter' => [['parameter' => 'tenant'], 'parameter'],
    'a user exemption with a parameter' => [['subject' => 'user', 'parameter' => 'web', 'match' => hash('sha256', 'User:42')], 'parameter'],
    'a match that is not a string' => [['match' => null], 'match'],
    'an ip match that is not a range' => [['match' => 'not-an-ip'], 'match'],
    'an ip match that is not normalized' => [['match' => '203.0.113.7/24'], 'match'],
    'an ip match without its prefix' => [['match' => '203.0.113.7'], 'match'],
    'a user match that is not a hash' => [['subject' => 'user', 'match' => 'App\Models\User:42'], 'match'],
    'a custom match in upper case' => [['subject' => 'custom', 'parameter' => 'tenant', 'match' => strtoupper(hash('sha256', 'acme'))], 'match'],
    'a custom match one character short' => [['subject' => 'custom', 'parameter' => 'tenant', 'match' => substr(hash('sha256', 'acme'), 1)], 'match'],
    'a string expiry' => [['expires_at' => '1800000060'], 'expires_at'],
    'a float expiry' => [['expires_at' => 1_800_000_060.0], 'expires_at'],
    'an expiry of 0' => [['expires_at' => 0], 'expires_at'],
]);
