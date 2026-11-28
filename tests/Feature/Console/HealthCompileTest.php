<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Compiler\InvalidRuleSkipped;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

/*
| SPEC-037, issue #205: the compile check, which names the rows the compile skips
| as invalid, with the compile's own reasons: enabled rules, the exemptions it would
| compile, and settings. Every check passes before each test changes one thing
| (Health::passing()). Each test saves its rows with the error tracker faked: the
| compile a save starts reports them.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 11:00:30', 'UTC'));
    Health::passing();
    Exceptions::fake();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

const COMPILE_OK = 'Every enabled rule, and every exemption and setting the manifest would hold, compiles.';

function compileWarning(int $rows, string $messages): array
{
    return ['warning', "The compile skips {$rows} invalid ".($rows === 1 ? 'row, so it applies' : 'rows, so they apply').' nowhere: '
        .$messages.' Fix or delete '.($rows === 1 ? 'it' : 'them').', then run php artisan dynamic-throttle:compile.'];
}

function invalidExemption(?int $rule, string $value = 'not-an-ip'): int
{
    return (int) DB::table('dynamic_throttle_exemptions')->insertGetId(['rule_id' => $rule, 'subject' => 'ip', 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
}

it('AC-5: is ok when every row compiles', function (): void {
    expect(Health::check('compile'))->toBe(['ok', COMPILE_OK]);
});

it('AC-5: warns about an enabled rule the compile skips, with the compile\'s reason', function (array $attributes, string $reason): void {
    $rule = Throttling::rule($attributes);

    expect(Health::check('compile'))->toBe(compileWarning(1, "Rule {$rule->id} was skipped: {$reason}"));
})->with([
    'a window of 0 seconds' => [['window_seconds' => 0], 'window_seconds must be from 1 to 86400, got 0.'],
    'a header scope without a header' => [['scope' => 'header'], 'scope_parameter is required for the header scope.'],
]);

it('AC-5: never names a disabled rule', function (): void {
    Throttling::rule(['window_seconds' => 0, 'enabled' => false]);

    expect(Health::check('compile'))->toBe(['ok', COMPILE_OK]);
});

it('AC-6: warns about an invalid exemption of an enabled rule and an invalid global one, never one of a disabled rule', function (): void {
    $enabled = Rule::query()->sole();
    $disabled = Throttling::rule(['name' => 'Off', 'enabled' => false]);
    $ofEnabled = invalidExemption($enabled->id);
    $global = invalidExemption(null, '999.0.0.1');
    invalidExemption($disabled->id);

    expect(Health::check('compile'))->toBe(compileWarning(2,
        "Exemption {$ofEnabled} was skipped: value must be an IP address or a CIDR range, such as 10.0.0.0/8, got \"not-an-ip\". "
        ."Exemption {$global} was skipped: value must be an IP address or a CIDR range, such as 10.0.0.0/8, got \"999.0.0.1\"."));
});

it('AC-6: warns about an invalid settings row', function (): void {
    $setting = (int) DB::table('dynamic_throttle_settings')->insertGetId(['name' => 'scale_factor', 'value' => '99', 'created_at' => now(), 'updated_at' => now()]);

    expect(Health::check('compile'))->toBe(compileWarning(1, "Setting {$setting} was skipped: scale_factor's value must be from 0.1 to 10, with at most two decimals, got \"99\"."));
});

it('AC-7: names the first ten rows, then how many more, and writes, compiles and reports nothing', function (): void {
    $ids = [];

    foreach (range(1, 12) as $n) {
        $ids[] = Throttling::rule(['name' => "Broken {$n}", 'window_seconds' => 0])->id;
    }

    $manifest = Cache::store('health')->get(app(KeyBuilder::class)->manifest());
    $handler = Exceptions::fake();
    $messages = implode(' ', array_map(fn (int $id): string => "Rule {$id} was skipped: window_seconds must be from 1 to 86400, got 0.", array_slice($ids, 0, 10)));

    expect(Health::check('compile'))->toBe(compileWarning(12, $messages.' And 2 more.'))
        ->and(Cache::store('health')->get(app(KeyBuilder::class)->manifest()))->toBe($manifest)
        ->and(array_filter($handler->reported(), fn (Throwable $reported): bool => $reported instanceof InvalidRuleSkipped))->toBe([]);
});
