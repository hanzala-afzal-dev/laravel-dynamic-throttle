<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Compiler\CompileResult;
use Afzal\DynamicThrottle\Compiler\InvalidRuleSkipped;
use Afzal\DynamicThrottle\Compiler\ManifestCompileFailed;
use Afzal\DynamicThrottle\Compiler\ManifestCompiler;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Manifest\ManifestState;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-006 AC-1 to AC-9: compiling the rules table into the manifest.
*/

beforeEach(function (): void {
    ManifestRepository::flushState();
    RuleSchema::migrate();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * @return array{format: int, version: int, rules: list<array<string, mixed>>}|null
 */
function storedManifest(): ?array
{
    $json = Cache::get('dt:m:3');

    return is_string($json) ? json_decode($json, true, flags: JSON_THROW_ON_ERROR) : null;
}

/**
 * @return list<string>
 */
function storedRuleIds(): array
{
    return array_column(storedManifest()['rules'] ?? [], 'id');
}

/**
 * A rules row written without Eloquent, so it can hold values the model would refuse.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertRuleRow(array $overrides = []): int
{
    return (int) DB::table('dynamic_throttle_rules')->insertGetId([
        'name' => 'API',
        'pattern' => 'api/*',
        'scope' => 'ip',
        'max_attempts' => 60,
        'window_seconds' => 60,
        ...$overrides,
    ]);
}

/**
 * A cache repository whose writes fail once $failing is set.
 *
 * @return object{store: ArrayStore&object{failing: bool}, cache: Repository}
 */
function failingWritesCache(): object
{
    $store = new class extends ArrayStore
    {
        public bool $failing = false;

        public function put($key, $value, $seconds): bool
        {
            return $this->failing ? throw new RuntimeException('The store is down.') : parent::put($key, $value, $seconds);
        }

        public function forever($key, $value): bool
        {
            return $this->failing ? throw new RuntimeException('The store is down.') : parent::forever($key, $value);
        }
    };

    return (object) ['store' => $store, 'cache' => new Repository($store)];
}

it('AC-1: compiles the enabled rules, sorted by priority, then id', function (): void {
    foreach ([100, 10, 100, 10] as $index => $priority) {
        Rule::create(RuleSchema::attributes(['priority' => $priority, 'enabled' => $index !== 2]));
    }

    app(ManifestCompiler::class)->compile();

    expect(storedRuleIds())->toBe(['2', '4', '1']);
});

it('AC-2: writes each rule with exactly the fields requests need, and enum values as strings', function (): void {
    $rule = Rule::create(RuleSchema::attributes([
        'description' => 'Not needed on the request path.',
        'scope' => Scope::Header,
        'scope_parameter' => 'X-Api-Key',
        'response_override' => ['message' => 'Slow down'],
    ]));

    app(ManifestCompiler::class)->compile();

    expect(storedManifest()['rules'])->toBe([[
        'id' => (string) $rule->id,
        'name' => 'API',
        'method' => '*',
        'pattern' => 'api/*',
        'scope' => 'header',
        'scope_parameter' => 'X-Api-Key',
        'max_attempts' => 60,
        'window_seconds' => 60,
        'priority' => 100,
        'mode' => 'enforce',
        'on_failure' => 'open',
        'on_missing_identity' => 'ip',
        'response_override' => ['message' => 'Slow down'],
    ]]);
});

it('AC-3: only ever increases the version, even after the manifest is deleted', function (): void {
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'UTC'));
    $compiler = app(ManifestCompiler::class);

    $first = $compiler->compile()->version;
    $sameMillisecond = $compiler->compile()->version;
    Cache::forget('dt:m:3');
    $this->travel(1)->seconds();
    $afterDelete = $compiler->compile()->version;

    expect([$first, $sameMillisecond, $afterDelete])->toBe([1_798_761_600_000, 1_798_761_600_001, 1_798_761_601_000])
        ->and(storedManifest()['version'])->toBe(1_798_761_601_000);
});

it('AC-4: skips and reports an invalid row, and still compiles the others', function (array $invalid, string $reason): void {
    Exceptions::fake();
    $valid = insertRuleRow();
    $skipped = insertRuleRow($invalid);

    $result = app(ManifestCompiler::class)->compile();

    expect(storedRuleIds())->toBe([(string) $valid])
        ->and([$result->rules, $result->skipped])->toBe([1, 1]);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (InvalidRuleSkipped $exception): bool => str_starts_with($exception->getMessage(), "Rule {$skipped} was skipped: ")
        && str_contains($exception->getMessage(), $reason));
})->with([
    'an unknown method' => [['method' => 'FETCH'], 'method'],
    'an unknown scope' => [['scope' => 'planet'], 'scope'],
    'an unknown mode' => [['mode' => 'maybe'], 'mode'],
    'an unknown failure policy' => [['on_failure' => 'sometimes'], 'on_failure'],
    'an unknown missing-identity policy' => [['on_missing_identity' => 'guess'], 'on_missing_identity'],
    'max 0' => [['max_attempts' => 0], 'max_attempts'],
    'window 0' => [['window_seconds' => 0], 'window_seconds'],
    'window 86,401' => [['window_seconds' => 86_401], 'window_seconds'],
    'an empty pattern' => [['pattern' => ''], 'pattern'],
    'a header rule without a parameter' => [['scope' => 'header'], 'scope_parameter'],
    'a custom rule without a parameter' => [['scope' => 'custom'], 'scope_parameter'],
    'a response override that is not JSON' => [['response_override' => '{'], 'response_override'],
]);

it('AC-5: compiles zero enabled rules to a manifest with no rules, which readers treat as fresh', function (): void {
    Rule::create(RuleSchema::attributes(['enabled' => false]));

    app(ManifestCompiler::class)->compile();
    $manifest = app(ManifestRepository::class)->current();

    expect(storedManifest()['rules'])->toBe([])
        ->and($manifest->state)->toBe(ManifestState::Fresh)
        ->and($manifest->rules())->toBe([]);
});

it('AC-6: leaves the stored manifest untouched when reading the rules fails', function (): void {
    Rule::create(RuleSchema::attributes());
    app(ManifestCompiler::class)->compile();
    $before = Cache::get('dt:m:3');
    Schema::drop('dynamic_throttle_rules');

    expect(fn () => app(ManifestCompiler::class)->compile())
        ->toThrow(function (ManifestCompileFailed $exception): void {
            expect($exception->getPrevious())->toBeInstanceOf(QueryException::class);
        })
        ->and(Cache::get('dt:m:3'))->toBe($before);
});

it('AC-6: leaves the stored manifest untouched when writing it fails', function (): void {
    Rule::create(RuleSchema::attributes());
    $failing = failingWritesCache();
    $compiler = new ManifestCompiler($failing->cache, new KeyBuilder('dt:'));
    $compiler->compile();
    $before = $failing->cache->get('dt:m:3');
    $failing->store->failing = true;

    expect(fn (): CompileResult => $compiler->compile())
        ->toThrow(function (ManifestCompileFailed $exception): void {
            expect($exception->getPrevious()?->getMessage())->toBe('The store is down.');
        })
        ->and($failing->cache->get('dt:m:3'))->toBe($before);
});

it('AC-7: waits for the compile lock, then fails without writing', function (): void {
    Rule::create(RuleSchema::attributes());
    Cache::forget('dt:m:3');
    $held = Cache::lock('dt:m:3:lock', 10);
    $held->get();

    try {
        expect(fn (): CompileResult => (new ManifestCompiler(Cache::store(), new KeyBuilder('dt:'), lockWaitSeconds: 0))->compile())
            ->toThrow(ManifestCompileFailed::class)
            ->and(Cache::get('dt:m:3'))->toBeNull();
    } finally {
        $held->release();
    }
});

it('AC-8: compiles after a save commits, and not for a save that rolls back', function (): void {
    Cache::forget('dt:m:3');

    try {
        DB::transaction(function (): void {
            Rule::create(RuleSchema::attributes());

            throw new RuntimeException('Roll back.');
        });
    } catch (RuntimeException) {
        // Expected: the transaction rolled back.
    }

    $rolledBack = storedManifest();
    $rule = DB::transaction(fn (): Rule => Rule::create(RuleSchema::attributes()));
    $afterCreate = storedManifest();
    $rule->update(['max_attempts' => 10]);
    $afterUpdate = storedManifest();
    $rule->delete();
    $afterDelete = storedManifest();

    expect($rolledBack)->toBeNull()
        ->and(array_column($afterCreate['rules'] ?? [], 'id'))->toBe([(string) $rule->id])
        ->and($afterUpdate['rules'][0]['max_attempts'] ?? null)->toBe(10)
        ->and($afterUpdate['version'] ?? 0)->toBeGreaterThan($afterCreate['version'] ?? 0)
        ->and($afterDelete['rules'] ?? null)->toBe([]);
});

it('AC-9: saves the rule and reports the failure when the observer cannot compile', function (): void {
    Exceptions::fake();
    $failing = failingWritesCache();
    $failing->store->failing = true;
    app()->instance(ManifestCompiler::class, new ManifestCompiler($failing->cache, new KeyBuilder('dt:')));

    Rule::create(RuleSchema::attributes());

    expect(Rule::query()->count())->toBe(1);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(ManifestCompileFailed::class);
});

it('AC-22: writes only its own format\'s key, and leaves the manifest of another format alone', function (): void {
    // As a server of another version of the package keeps it, during a rolling deploy.
    Cache::forever('dt:m:4', '{"format":4}');

    Rule::create(RuleSchema::attributes());

    expect(Cache::get('dt:m:4'))->toBe('{"format":4}')
        ->and(storedRuleIds())->toHaveCount(1);
});
