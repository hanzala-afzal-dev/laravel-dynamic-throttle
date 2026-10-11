<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Support\LimiterStoreFactory;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-016 AC-1 to AC-3: dynamic-throttle:health prints one line per check,
| or one JSON object, exits 1 on an error, or on a warning with --strict, and
| changes nothing. SQLite and the array cache store; the clock stands at
| 2027-01-15 11:00:30 UTC.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 11:00:30', 'UTC'));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * The keys and values of the cache store the package uses, and every row of every table.
 *
 * @return array{array<string, mixed>, array<string, list<array<string, mixed>>>}
 */
function healthEverything(string $store = 'health'): array
{
    $array = Cache::store($store)->getStore();

    if (! $array instanceof ArrayStore) {
        throw new RuntimeException('The store is not kept in memory.');
    }

    $rows = [];

    foreach (Schema::getTableListing() as $table) {
        $rows[$table] = DB::table($table)->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return [$array->all(), $rows];
}

it('AC-1: prints one line per check, in order, with its status, id and message, then how many warned and erred, and exits 0', function (): void {
    Health::passing();

    [$output, $exitCode] = Health::text();
    $lines = Health::lines($output);
    // The cache driver keeps no traffic per client, which only the Redis driver checks.
    $skipped = array_search('dominant-ip', Health::CHECKS, true);

    expect($exitCode)->toBe(0, $output)
        ->and(array_column($lines, 1))->toBe(Health::CHECKS)
        ->and(array_unique(array_column($lines, 0)))->toBe([0 => 'OK', $skipped => 'SKIPPED'])
        ->and(array_filter(array_column($lines, 2), fn (string $message): bool => $message === ''))->toBe([])
        ->and($output)->toContain('0 warnings, 0 errors');
});

it('AC-1: prints the same report as one JSON object, with the same checks in the same order', function (): void {
    Health::passing();

    [$exitCode, $report] = Health::report();
    $skipped = array_search('dominant-ip', Health::CHECKS, true);

    expect($exitCode)->toBe(0)
        ->and(array_keys($report))->toBe(['status', 'checks'])
        ->and($report['status'])->toBe('ok')
        ->and(array_column($report['checks'], 'check'))->toBe(Health::CHECKS)
        ->and(array_map(array_keys(...), $report['checks']))->each->toBe(['check', 'status', 'message'])
        ->and(array_unique(array_column($report['checks'], 'status')))->toBe([0 => 'ok', $skipped => 'skipped']);
});

it('AC-1: prints the app\'s text as explain does: control characters escaped in text, and JSON in ASCII only', function (): void {
    Health::passing();
    $rule = Throttling::rule(['name' => "Café ✓ Evil\x1b]0;owned\x07", 'pattern' => 'admin/*']);
    Telemetry::buffer()->record(Telemetry::minute(Carbon::now()->getTimestamp()) - 5, 30, [Telemetry::ignored((string) $rule->id)]);

    [$text] = Health::text();
    // Read once: Artisan::output() empties what it reads, as Health::report() does.
    Artisan::call('dynamic-throttle:health', ['--json' => true]);
    $json = Artisan::output();

    expect($text)->not->toContain("\x1b")->not->toContain("\x07")->toContain('Café ✓ Evil\x1B]0;owned\x07')
        ->and(preg_match('/[^\x00-\x7F]/', $json))->toBe(0)
        ->and($json)->toContain('Caf\u00e9 \u2713 Evil\u001b]0;owned\u0007');
});

it('AC-2: makes a check that errs the status, and exits 1', function (): void {
    Health::passing();
    Cache::store('health')->forget(app(KeyBuilder::class)->manifest());

    [$exitCode, $report] = Health::report();
    [$output, $textExitCode] = Health::text();

    expect([$exitCode, $report['status'], $textExitCode])->toBe([1, 'error', 1])
        ->and($output)->toContain('0 warnings, 1 error');
});

it('AC-2: makes a check that warns the status, and exits 0, or 1 with --strict', function (): void {
    Health::passing();
    config()->set('app.key', '');

    [$exitCode, $report] = Health::report();
    [$strictExitCode] = Health::report(['--strict' => true]);
    [$output, $textExitCode] = Health::text(['--strict' => true]);

    expect([$exitCode, $report['status'], $strictExitCode, $textExitCode])->toBe([0, 'warning', 1, 1])
        ->and($output)->toContain('1 warning, 0 errors');
});

it('AC-2: counts a skipped check as ok, also with --strict', function (): void {
    Health::passing();
    config()->set('dynamic-throttle.telemetry.enabled', false);

    [$exitCode, $report] = Health::report(['--strict' => true]);

    expect([$exitCode, $report['status']])->toBe([0, 'ok'])
        ->and(array_column(array_filter($report['checks'], fn (array $check): bool => $check['status'] === 'skipped'), 'check'))
        ->toBe(['metrics', 'guests', 'dominant-ip', 'ignored']);
});

it('AC-3: writes nothing, to the cache store or the database, and compiles nothing', function (Closure $state): void {
    Health::passing();
    $state();
    $before = healthEverything();

    Health::report();
    Health::text();

    expect(healthEverything())->toBe($before);
})->with([
    'every check passing' => [fn (): null => null],
    'no manifest, which a request would rebuild' => [fn (): bool => Cache::store('health')->forget(app(KeyBuilder::class)->manifest())],
    'no metrics flushed, which a flush would mark' => [fn (): bool => Cache::store('health')->forget(app(KeyBuilder::class)->telemetryFlushed())],
]);

it('AC-3: reports a check that throws as an error with the exception\'s message, and still runs every other check', function (): void {
    Health::passing();
    app()->bind(LimiterStoreFactory::class, fn (): never => throw new RuntimeException('The factory broke.'));

    $checks = Health::checks();

    expect($checks['config'])->toBe(['error', 'The factory broke.'])
        ->and(array_keys($checks))->toBe(Health::CHECKS)
        ->and([$checks['app-key'][0], $checks['attached'][0], $checks['manifest'][0], $checks['metrics'][0]])->toBe(['ok', 'ok', 'ok', 'ok']);
});

it('AC-3: reports a telemetry buffer that cannot be built on the checks that read it, and runs the others', function (): void {
    Health::passing();
    app()->bind(TelemetryBuffer::class, fn (): never => throw new RuntimeException('The buffer broke.'));

    $checks = Health::checks();

    expect($checks['metrics'][0])->toBe('error')
        ->and($checks['metrics'][1])->toContain('The buffer broke.')
        ->and([$checks['config'][0], $checks['manifest'][0], $checks['attached'][0]])->toBe(['ok', 'ok', 'ok']);
});
