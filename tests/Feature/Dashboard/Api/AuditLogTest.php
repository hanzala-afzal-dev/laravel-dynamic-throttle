<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\AuditAction;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\AuditEntry;
use Afzal\DynamicThrottle\Models\AuditLogEntry;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-019 AC-1 to AC-5 and AC-7: GET api/audit, newest first in cursor pages,
| with filters, through a read-only model. Entries are written at the times the
| clock is moved to, on 2027-01-15 in UTC.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * An entry written at a time of 2027-01-15, such as "11:00", in UTC.
 *
 * @param  array<string, mixed>  $attributes
 */
function auditEntryAt(string $time, array $attributes = []): AuditEntry
{
    test()->travelTo(Carbon::parse("2027-01-15 {$time}:00", 'UTC'));

    return AuditEntry::query()->create([
        'actor' => 'cli:deploy',
        'action' => AuditAction::SettingsBypass,
        'target' => 'setting:bypass_all',
        'before' => null,
        'after' => ['value' => '1', 'expires_at' => null],
        ...$attributes,
    ]);
}

/**
 * The entries' ids a query of api/audit lists, as a viewer.
 *
 * @param  array<string, string>  $query
 * @return list<int>
 */
function auditIds(TestCase $test, array $query = []): array
{
    $data = DashboardApi::send($test, 'GET', 'audit?'.http_build_query($query), user: DashboardApi::viewer())->json('data');

    return is_array($data) ? array_column($data, 'id') : [];
}

it('AC-1: lists entries newest first, with every field, before and after as written', function (): void {
    $first = auditEntryAt('10:00');
    $second = auditEntryAt('11:00', [
        'actor' => DashboardApi::managerActor(), 'action' => AuditAction::RuleDisable, 'target' => 'rule:12',
        'before' => ['enabled' => true], 'after' => ['enabled' => false], 'ip' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0',
    ]);
    $third = auditEntryAt('11:30');

    $response = DashboardApi::send($this, 'GET', 'audit', user: DashboardApi::viewer());

    expect($response->status())->toBe(200)
        ->and(array_column($response->json('data'), 'id'))->toBe([$third->id, $second->id, $first->id])
        ->and($response->json('data.1'))->toBe([
            'id' => $second->id,
            'actor' => DashboardApi::managerActor(),
            'action' => 'rule.disable',
            'target' => 'rule:12',
            'before' => ['enabled' => true],
            'after' => ['enabled' => false],
            'ip' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => '2027-01-15T11:00:00+00:00',
        ])
        ->and($response->json('data.0.before'))->toBeNull()
        ->and([$response->json('per_page'), $response->json('next_cursor')])->toBe([100, null]);
});

it('AC-2: gives 100 entries a page, and the next page through next_cursor', function (): void {
    foreach (range(1, 101) as $number) {
        auditEntryAt('10:00', ['target' => "rule:{$number}"]);
    }

    $first = DashboardApi::send($this, 'GET', 'audit', user: DashboardApi::viewer());
    $second = DashboardApi::send($this, 'GET', 'audit?'.http_build_query(['cursor' => $first->json('next_cursor')]), user: DashboardApi::viewer());

    expect([count($first->json('data')), is_string($first->json('next_cursor'))])->toBe([100, true])
        ->and([array_column($second->json('data'), 'target'), $second->json('next_cursor')])->toBe([['rule:1'], null]);
});

it('AC-3: narrows the list with filters, which combine', function (array $query, array $expected): void {
    $entries = [
        'bypass' => auditEntryAt('10:00'),
        'disable' => auditEntryAt('11:00', ['actor' => DashboardApi::managerActor(), 'action' => AuditAction::RuleDisable, 'target' => 'rule:12']),
        'update' => auditEntryAt('11:30', ['actor' => DashboardApi::managerActor(), 'action' => AuditAction::RuleUpdate, 'target' => 'rule:12']),
        'enable' => auditEntryAt('12:00', ['action' => AuditAction::RuleEnable, 'target' => 'rule:12']),
    ];

    expect(auditIds($this, $query))->toBe(array_map(fn (string $name): int => $entries[$name]->id, $expected));
})->with([
    'an action' => [['action' => 'rule.disable'], ['disable']],
    'a target' => [['target' => 'rule:12'], ['enable', 'update', 'disable']],
    'an actor' => [['actor' => 'cli:deploy'], ['enable', 'bypass']],
    'from, included' => [['from' => '2027-01-15T11:00:00+00:00'], ['enable', 'update', 'disable']],
    'until, included' => [['until' => '2027-01-15T11:00:00Z'], ['disable', 'bypass']],
    'all of them' => [['target' => 'rule:12', 'actor' => 'user:'.TestUser::class.':2', 'from' => '2027-01-15T11:15:00Z'], ['update']],
]);

it('AC-3: refuses a filter it cannot use with a 422 that names it', function (array $query, string $field): void {
    $response = DashboardApi::send($this, 'GET', 'audit?'.http_build_query($query), user: DashboardApi::viewer());

    expect([$response->status(), array_keys($response->json('errors') ?? [])])->toBe([422, [$field]]);
})->with([
    'an unknown action' => [['action' => 'rule.explode'], 'action'],
    'a from that is not a time' => [['from' => 'yesterday-ish'], 'from'],
    'a cursor that is not one' => [['cursor' => 'not-a-cursor'], 'cursor'],
    'until before from' => [['from' => '2027-01-15T12:00:00Z', 'until' => '2027-01-15T11:00:00Z'], 'until'],
]);

it('AC-4: lets a viewer and a manager read it, and writes nothing', function (): void {
    auditEntryAt('10:00');

    // The guest first: actingAs() lasts for the rest of the test.
    $guest = DashboardApi::send($this, 'GET', 'audit');
    $viewer = DashboardApi::send($this, 'GET', 'audit', user: DashboardApi::viewer());
    $manager = DashboardApi::send($this, 'GET', 'audit', user: DashboardApi::manager());

    expect([$viewer->status(), $manager->status(), $guest->status(), AuditEntry::query()->count()])->toBe([200, 200, 403, 1]);
});

it('AC-5: refuses to create, change or delete an entry through the read-only model', function (string $write): void {
    $id = auditEntryAt('10:00')->id;

    $attempt = match ($write) {
        'create' => fn (): mixed => AuditLogEntry::query()->create(['actor' => 'x', 'action' => 'rule.update', 'target' => 'rule:1']),
        'update' => fn (): mixed => AuditLogEntry::query()->findOrFail($id)->forceFill(['actor' => 'someone else'])->save(),
        'delete' => fn (): mixed => AuditLogEntry::query()->findOrFail($id)->delete(),
    };

    expect($attempt)->toThrow(LogicException::class)
        ->and(AuditEntry::query()->pluck('actor')->all())->toBe(['cli:deploy']);
})->with(['create', 'update', 'delete']);

it('AC-7: answers 503 with the way out when the audit table is missing', function (): void {
    Schema::connection((new AuditEntry)->getConnectionName())->drop((new AuditEntry)->getTable());

    $response = DashboardApi::send($this, 'GET', 'audit', user: DashboardApi::viewer());

    expect($response->status())->toBe(503)
        ->and($response->json('message'))->toStartWith('The dynamic_throttle_audit_log table does not exist.');
});
