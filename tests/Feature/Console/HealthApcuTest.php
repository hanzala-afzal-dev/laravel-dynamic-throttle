<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Support\Health\Runtime;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;

/*
| Issue #100: the apcu check. On PHP-FPM without APCu, each request reads the
| rules from the cache store first, and the workers share no cooldown, so a
| Redis outage costs every request a try. The command line cannot see the web
| server's PHP, so the check says so there, and the dashboard's Settings page,
| which runs the checks in the web server, shows the real answer. Every check
| passes before each test changes one thing (Health::passing()).
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 11:00:30', 'UTC'));
    Health::passing();
});

afterEach(function (): void {
    ManifestRepository::flushState();
    unset($_SERVER['LARAVEL_OCTANE']);
});

const APCU_WARNING = 'PHP-FPM runs without APCu, so every request reads the rules from the cache store first, and the workers do not share the counter store\'s cooldown: during an outage, every request tries the store again. Install and enable the APCu extension (docs/guide/storage-and-runtimes.md#runtimes).';

it('is listed after app-key', function (): void {
    expect(array_keys(Health::checks()))->toBe(Health::CHECKS)
        ->and(array_slice(Health::CHECKS, 3, 2))->toBe(['app-key', 'apcu']);
});

it('warns on PHP-FPM without APCu, and says what it costs', function (): void {
    Health::runtime('fpm-fcgi', apcu: false);

    expect(Health::check('apcu'))->toBe(['warning', APCU_WARNING]);
});

it('warns in any web server that starts PHP per request, naming its SAPI', function (): void {
    Health::runtime('apache2handler', apcu: false);

    expect(Health::check('apcu'))->toBe(['warning', str_replace('PHP-FPM runs', 'PHP (apache2handler) runs', APCU_WARNING)]);
});

it('is ok on PHP-FPM with APCu', function (): void {
    expect(Health::check('apcu'))->toBe(['ok', 'PHP-FPM\'s workers share the rules and the counter store\'s cooldown in APCu.']);
});

it('is ok on Octane, with or without APCu, where each worker keeps them in its memory', function (bool $apcu): void {
    Health::runtime('cli', apcu: $apcu, octane: true);

    expect(Health::check('apcu'))->toBe(['ok', 'On Octane, each worker keeps the rules and the counter store\'s cooldown in its memory.']);
})->with([true, false]);

it('says the command line cannot tell, and where to look, instead of warning', function (string $sapi): void {
    Health::runtime($sapi, apcu: false);

    expect(Health::check('apcu'))->toBe(['skipped', 'The command line cannot see the PHP your web server runs: the dashboard\'s Settings page runs this check there.']);
})->with(['cli', 'phpdbg']);

it('shows the warning on the dashboard, whose checks run in the web server', function (): void {
    DashboardApi::prepare();
    Health::passing();
    Health::runtime('fpm-fcgi', apcu: false);

    $response = DashboardApi::send($this, 'GET', 'health', user: DashboardApi::viewer());
    $apcu = collect($response->json('checks'))->firstWhere('check', 'apcu');

    expect([$response->status(), $response->json('status'), $apcu])->toBe([200, 'warning', ['check' => 'apcu', 'status' => 'warning', 'message' => APCU_WARNING]]);
});

it('reads the PHP it runs in: its SAPI, APCu, and Octane, which marks its workers as Laravel does', function (): void {
    $plain = Runtime::current();
    $_SERVER['LARAVEL_OCTANE'] = 1;
    $octane = Runtime::current();

    expect([$plain->sapi, $plain->apcu, $plain->octane, $octane->octane])
        ->toBe([PHP_SAPI, function_exists('apcu_enabled') && apcu_enabled(), false, true]);
});
