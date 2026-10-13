<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/*
| SPEC-029 AC-4: the runbook's "An emergency change was left on"
| (docs/runbook.md#an-emergency-change-was-left-on). The test follows the
| section's steps, and checks what the section says you will see. Every
| other health check passes, /api/* has a rule of 60 requests a minute for
| each IP, and the app runs in production. The clock starts at 2027-01-15 08:00:00 UTC.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 08:00:00', 'UTC'));
    Health::passing();
    $this->app['env'] = 'production';
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * The status and the rate-limit headers of each response.
 *
 * @param  list<TestResponse<Response>>  $responses
 * @return list<array{int, array<string, string>}>
 */
function leftOnResponses(array $responses): array
{
    return array_map(fn (TestResponse $response): array => [$response->status(), Throttling::headers($response)], $responses);
}

it('AC-4: limits requests again an hour after bypass on without --for, with no command run, and status shows the end time as passed', function (): void {
    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--force' => true])->assertExitCode(0);
    $during = [$this->get('api/x'), $this->get('api/x')];

    $this->travelTo(Carbon::parse('2027-01-15 09:00:00', 'UTC'));
    $after = $this->get('api/x');

    $this->artisan('dynamic-throttle:status')
        ->expectsOutputToContain('ended at 2027-01-15 09:00:00 UTC')
        ->assertExitCode(0);
    expect(leftOnResponses($during))->toBe([[200, []], [200, []]])
        ->and(leftOnResponses([$after]))->toBe([[200, ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '59']]]);
});

it('AC-4: shows bypass --forever in status, warns on emergency in health, which exits 1 with --strict, and bypass off ends both', function (): void {
    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--forever' => true, '--force' => true])->assertExitCode(0);

    $this->artisan('dynamic-throttle:status')
        ->expectsOutputToContain('on, with no end time')
        ->assertExitCode(0);
    [$strictExit] = Health::report(['--strict' => true]);
    $warned = Health::check('emergency');
    $this->artisan('dynamic-throttle:bypass', ['state' => 'off'])->assertExitCode(0);
    [$strictExitAfter] = Health::report(['--strict' => true]);
    $counted = $this->get('api/x');

    $this->artisan('dynamic-throttle:status')
        ->doesntExpectOutputToContain('with no end time')
        ->assertExitCode(0);
    expect([$strictExit, $warned[0]])->toBe([1, 'warning'])
        ->and($warned[1])->toContain('php artisan dynamic-throttle:bypass off')
        ->and([$strictExitAfter, Health::status('emergency')])->toBe([0, 'ok'])
        ->and(Throttling::headers($counted))->toBe(['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '59']);
});
