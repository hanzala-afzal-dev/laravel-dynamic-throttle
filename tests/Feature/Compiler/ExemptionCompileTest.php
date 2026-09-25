<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Compiler\ExemptionsTableMissing;
use Afzal\DynamicThrottle\Compiler\InvalidExemptionSkipped;
use Afzal\DynamicThrottle\Compiler\ManifestCompiler;
use Afzal\DynamicThrottle\Engine\Identity\IdentityHasher;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\Exemptions;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-009 AC-7 to AC-11: compiling the exemptions table into the manifest.
| The clock stands at T0, for the compiler and for requests alike.
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
});

/**
 * @return list<string>
 */
function storedExemptionIds(): array
{
    /** @var list<array{id: string}> $exemptions */
    $exemptions = Exemptions::stored()['exemptions'];

    return array_column($exemptions, 'id');
}

it('AC-7: compiles after an exemption is saved, updated or deleted, and not for a save that rolls back', function (): void {
    $rule = Exemptions::rule();
    $initial = Exemptions::stored();

    try {
        DB::transaction(function (): void {
            Exemption::forIp('10.0.0.0/8')->save();

            throw new RuntimeException('Roll back.');
        });
    } catch (RuntimeException) {
        // Expected: the transaction rolled back.
    }

    $rolledBack = Exemptions::stored();
    $exemption = Exemption::forIp('10.0.0.1')->forRule($rule);
    DB::transaction(fn (): bool => $exemption->save());
    $afterSave = Exemptions::stored();
    $exemption->until(Carbon::createFromTimestamp(FakeClock::T0 + 60))->save();
    $afterUpdate = Exemptions::stored();
    $exemption->delete();
    $afterDelete = Exemptions::stored();

    expect($rolledBack)->toBe($initial)
        ->and(array_column($afterSave['exemptions'], 'id'))->toBe([(string) $exemption->id])
        ->and($afterSave['version'])->toBeGreaterThan($initial['version'])
        ->and(array_column($afterUpdate['exemptions'], 'expires_at'))->toBe([FakeClock::T0 + 60])
        ->and($afterUpdate['version'])->toBeGreaterThan($afterSave['version'])
        ->and($afterDelete['exemptions'])->toBe([])
        ->and($afterDelete['version'])->toBeGreaterThan($afterUpdate['version']);
});

it('AC-8: writes format 2, with each exemption\'s fields, in id order', function (): void {
    $rule = Exemptions::rule();
    $global = Exemption::forIp('10.0.0.0/8')->because('The office network');
    $global->save();
    $ofRule = Exemption::forIp('2001:db8::/32')->forRule($rule)->until(Carbon::createFromTimestamp(FakeClock::T0 + 60));
    $ofRule->save();
    $custom = Exemption::forCustom('tenant', 'acme');
    $custom->save();

    $manifest = Exemptions::stored();

    expect($manifest['format'])->toBe(2)
        ->and(array_slice($manifest['exemptions'], 0, 2))->toBe([
            ['id' => (string) $global->id, 'rule' => null, 'subject' => 'ip', 'parameter' => null, 'match' => '10.0.0.0/8', 'expires_at' => null],
            ['id' => (string) $ofRule->id, 'rule' => (string) $rule->id, 'subject' => 'ip', 'parameter' => null, 'match' => '2001:db8::/32', 'expires_at' => FakeClock::T0 + 60],
        ])
        ->and(array_keys($manifest['exemptions'][2]))->toBe(['id', 'rule', 'subject', 'parameter', 'match', 'expires_at'])
        ->and([$manifest['exemptions'][2]['id'], $manifest['exemptions'][2]['subject'], $manifest['exemptions'][2]['parameter']])
        ->toBe([(string) $custom->id, 'custom', 'tenant']);
});

it('AC-9: leaves out expired exemptions, and those of rules that are disabled or skipped as invalid', function (): void {
    Exceptions::fake();
    $disabled = Exemptions::rule(['enabled' => false]);
    $invalid = (int) DB::table('dynamic_throttle_rules')->insertGetId(RuleSchema::attributes(['scope' => 'planet']));
    $enabled = Exemptions::rule();
    Exemption::forIp('10.0.0.1')->until(Carbon::createFromTimestamp(FakeClock::T0))->save();
    $expiringLater = Exemption::forIp('10.0.0.2')->until(Carbon::createFromTimestamp(FakeClock::T0 + 1));
    $expiringLater->save();
    Exemption::forIp('10.0.0.3')->forRule($disabled)->save();
    Exemptions::insert(['rule_id' => $invalid, 'value' => '10.0.0.4']);
    $ofEnabled = Exemption::forIp('10.0.0.5')->forRule($enabled);
    $ofEnabled->save();

    $result = app(ManifestCompiler::class)->compile();

    expect(storedExemptionIds())->toBe([(string) $expiringLater->id, (string) $ofEnabled->id])
        ->and([$result->exemptions, $result->skippedExemptions])->toBe([2, 0]);
});

it('AC-10: skips and reports an invalid row, and still compiles the others', function (array $invalid, string $reason): void {
    Exceptions::fake();
    $valid = Exemptions::insert(['value' => '10.0.0.0/8']);
    $skipped = Exemptions::insert($invalid);

    $result = app(ManifestCompiler::class)->compile();

    expect(storedExemptionIds())->toBe([(string) $valid])
        ->and([$result->exemptions, $result->skippedExemptions])->toBe([1, 1]);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (InvalidExemptionSkipped $exception): bool => str_starts_with($exception->getMessage(), "Exemption {$skipped} was skipped: ")
        && str_contains($exception->getMessage(), $reason));
})->with([
    'a prefix longer than IPv4 has' => [['value' => '10.0.0.0/33'], '10.0.0.0/33'],
    'a prefix longer than IPv6 has' => [['value' => '2001:db8::/129'], '2001:db8::/129'],
    'a value that is not an IP' => [['value' => 'not-an-ip'], 'not-an-ip'],
    'a negative prefix' => [['value' => '10.0.0.1/-1'], '10.0.0.1/-1'],
    'an empty IP' => [['value' => ''], 'value'],
    'an empty user' => [['subject' => 'user', 'value' => ''], 'value'],
    'a user without a class' => [['subject' => 'user', 'value' => '42'], 'value'],
    'a user without an id' => [['subject' => 'user', 'value' => 'App\Models\User:'], 'value'],
    'a user with nothing before the id' => [['subject' => 'user', 'value' => ':42'], 'value'],
    'an empty custom value' => [['subject' => 'custom', 'subject_parameter' => 'tenant', 'value' => ''], 'value'],
    'a custom exemption without a scope name' => [['subject' => 'custom', 'value' => 'acme'], 'subject_parameter'],
    'a custom exemption with an empty scope name' => [['subject' => 'custom', 'subject_parameter' => '', 'value' => 'acme'], 'subject_parameter'],
    'a custom exemption with a name that is not a scope name' => [['subject' => 'custom', 'subject_parameter' => 'Tenant!', 'value' => 'acme'], 'subject_parameter'],
    'an unknown subject' => [['subject' => 'header', 'value' => 'X-Internal'], 'subject'],
]);

it('AC-10: compiles the rules alone, and reports the missing table, before the exemptions migration has run', function (): void {
    Exceptions::fake();
    $rule = Exemptions::rule();
    Schema::drop('dynamic_throttle_exemptions');

    $result = app(ManifestCompiler::class)->compile();

    expect(Exemptions::stored()['exemptions'])->toBe([])
        ->and(array_column(Exemptions::stored()['rules'], 'id'))->toBe([(string) $rule->id])
        ->and([$result->rules, $result->exemptions, $result->skippedExemptions])->toBe([1, 0, 0]);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (ExemptionsTableMissing $exception): bool => str_contains($exception->getMessage(), 'dynamic_throttle_exemptions'));
});

it('AC-11: writes no raw user id or custom value, only their hashes', function (): void {
    Exemption::forUser(new TestUser(['id' => 'user-QWERTY']))->save();
    Exemption::forCustom('tenant', 'tenant-QWERTY')->save();

    $matches = array_column(Exemptions::stored()['exemptions'], 'match');

    expect(Exemptions::json())->not->toContain('QWERTY')->not->toContain('TestUser')
        ->and($matches)->toHaveCount(2)
        ->and($matches)->each->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($matches[0])->not->toBe($matches[1]);
});

it('AC-11: hashes with the app key, so another key gives other hashes', function (): void {
    Exemption::forUser(new TestUser(['id' => 42]))->save();
    Exemption::forCustom('tenant', 'acme')->save();
    $first = array_column(Exemptions::stored()['exemptions'], 'match');

    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    app()->forgetInstance(IdentityHasher::class);
    app(ManifestCompiler::class)->compile();
    $second = array_column(Exemptions::stored()['exemptions'], 'match');

    expect($second[0])->not->toBe($first[0])
        ->and($second[1])->not->toBe($first[1])
        ->and($second)->each->toMatch('/\A[0-9a-f]{64}\z/');
});

it('AC-11: writes addresses and ranges normalized', function (string $written, string $match): void {
    Exemption::forIp($written)->save();

    expect(array_column(Exemptions::stored()['exemptions'], 'match'))->toBe([$match]);
})->with([
    'a range with host bits set' => ['10.0.0.1/8', '10.0.0.0/8'],
    'upper-case IPv6' => ['2001:DB8::/32', '2001:db8::/32'],
    'an IPv4 address' => ['203.0.113.7', '203.0.113.7/32'],
    'an IPv6 address' => ['2001:db8::1', '2001:db8::1/128'],
    'an IPv4-mapped address' => ['::ffff:203.0.113.7', '203.0.113.7/32'],
]);
