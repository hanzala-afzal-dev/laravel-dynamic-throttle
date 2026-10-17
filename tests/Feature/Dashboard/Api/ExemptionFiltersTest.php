<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Afzal\DynamicThrottle\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Carbon;

/*
| SPEC-023 AC-1: the filters of GET api/exemptions, for the Exemptions page. The
| clock stands at 2027-01-15 12:00:00 UTC.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 12:00:00', 'UTC'));
    $this->rule = Throttling::rule();
    $exemption = fn (array $attributes): Exemption => Exemption::query()->create(['subject' => ExemptionSubject::Ip, 'value' => '203.0.113.7', ...$attributes]);
    $exemption(['reason' => 'office, forever']);
    $exemption(['reason' => 'office, ended', 'expires_at' => Carbon::parse('2027-01-15 11:00:00', 'UTC')]);
    $exemption(['reason' => 'partner, on the rule', 'subject' => ExemptionSubject::User, 'value' => 'App\\Models\\User:42', 'rule_id' => $this->rule->id, 'expires_at' => Carbon::parse('2027-01-16 12:00:00', 'UTC')]);
    $exemption(['reason' => 'load test, on the rule, ended', 'rule_id' => $this->rule->id, 'expires_at' => Carbon::parse('2027-01-14 12:00:00', 'UTC')]);
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * The reasons the API lists for a query string, newest first.
 *
 * @return list<string>
 */
function exemptionReasons(TestCase $test, string $query): array
{
    return array_column(DashboardApi::send($test, 'GET', "exemptions?{$query}", user: DashboardApi::viewer())->json('data'), 'reason');
}

it('AC-1: filters by state, an exemption with no end being active', function (): void {
    expect([exemptionReasons($this, 'state=active'), exemptionReasons($this, 'state=expired')])->toBe([
        ['partner, on the rule', 'office, forever'],
        ['load test, on the rule, ended', 'office, ended'],
    ]);
});

it('AC-1: filters by subject, and by every rule or one, combined', function (): void {
    $rule = $this->rule->id;

    expect([
        exemptionReasons($this, 'subject=user'),
        exemptionReasons($this, 'rule=none'),
        exemptionReasons($this, "rule={$rule}&state=expired"),
        exemptionReasons($this, 'rule=none&subject=ip&state=active'),
    ])->toBe([
        ['partner, on the rule'],
        ['office, ended', 'office, forever'],
        ['load test, on the rule, ended'],
        ['office, forever'],
    ]);
});

it('AC-1: keeps the filters in the pages\' links', function (): void {
    $response = DashboardApi::send($this, 'GET', 'exemptions?state=active&subject=ip', user: DashboardApi::viewer());

    expect([$response->json('total'), $response->json('first_page_url')])->toBe([1, url('/dynamic-throttle/api/exemptions?state=active&subject=ip&page=1')]);
});

it('AC-1: refuses a filter it does not know the value of', function (string $query, string $field): void {
    $response = DashboardApi::send($this, 'GET', "exemptions?{$query}", user: DashboardApi::viewer());

    expect([$response->status(), array_keys($response->json('errors'))])->toBe([422, [$field]]);
})->with([
    ['state=soon', 'state'],
    ['subject=header', 'subject'],
    ['rule=some', 'rule'],
]);

it('AC-8: filters by state in any app time zone, east or west of UTC, an exemption ending now being expired', function (string $zone): void {
    config()->set('app.timezone', $zone);
    date_default_timezone_set($zone);

    try {
        $ip = fn (string $reason, string $ends): Exemption => Exemption::query()->create(['subject' => ExemptionSubject::Ip, 'value' => '198.51.100.1', 'reason' => $reason, 'expires_at' => Carbon::parse($ends, 'UTC')]);
        $ip('ends in an hour', '2027-01-15 13:00:00');
        $ip('ended an hour ago', '2027-01-15 11:00:00');
        $ip('ends now', '2027-01-15 12:00:00');
        $states = [exemptionReasons($this, 'state=active&subject=ip'), exemptionReasons($this, 'state=expired&subject=ip')];
    } finally {
        date_default_timezone_set('UTC');
    }

    expect($states)->toBe([
        ['ends in an hour', 'office, forever'],
        ['ends now', 'ended an hour ago', 'load test, on the rule, ended', 'office, ended'],
    ]);
})->with(['Asia/Karachi', 'America/Los_Angeles']);

it('AC-8: refuses a rule filter with a trailing newline, even where TrimStrings does not run', function (): void {
    TrimStrings::skipWhen(fn (): bool => true);

    try {
        $response = DashboardApi::send($this, 'GET', 'exemptions?rule=none%0A', user: DashboardApi::viewer());
    } finally {
        TrimStrings::flushState();
    }

    expect([$response->status(), array_keys($response->json('errors') ?? [])])->toBe([422, ['rule']]);
});
