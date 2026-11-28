<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\PlanFromConfig;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/*
| SPEC-037, issue #205: the scopes check, which names the custom scopes that the
| manifest's rules and exemptions use, and that no resolver is registered for. It
| errs when an enforcing, fail-closed rule uses one, since each request it matches
| answers 503. Every check passes before each test changes one thing
| (Health::passing()).
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 11:00:30', 'UTC'));
    Health::passing();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

const SCOPES_ADVICE = 'A rule that uses one fails to find the client on every request it matches: the error is reported, and the request goes through, or answers 503 on a fail-closed rule. '
    .'An exemption that uses one exempts nobody. Register them with DynamicThrottle::scope() in a service provider\'s boot().';

it('AC-8: warns about a custom rule and a custom exemption whose scope has no resolver, naming the scope and what uses it', function (): void {
    $rule = Throttling::rule(['name' => 'Per tenant', 'scope' => 'custom', 'scope_parameter' => 'tenant']);
    $exemption = Exemption::forCustom('plan', 'enterprise')->because('Enterprise plan');
    $exemption->save();

    expect(Health::check('scopes'))->toBe(['warning', "2 custom scopes are not registered: plan, for exemption {$exemption->id}. tenant, for rule {$rule->id}, Per tenant. ".SCOPES_ADVICE]);
});

it('AC-8: names every rule and exemption that uses one scope', function (): void {
    $first = Throttling::rule(['name' => 'Per tenant', 'scope' => 'custom', 'scope_parameter' => 'tenant']);
    $second = Throttling::rule(['name' => 'Tenant search', 'pattern' => 'api/search', 'scope' => 'custom', 'scope_parameter' => 'tenant']);

    expect(Health::check('scopes'))->toBe(['warning', "1 custom scope is not registered: tenant, for rule {$first->id}, Per tenant; rule {$second->id}, Tenant search. ".SCOPES_ADVICE]);
});

it('AC-8: is ok once the scope is registered, by a closure or by a ScopeResolver class', function (Closure|string $resolver): void {
    Throttling::rule(['name' => 'Per tenant', 'scope' => 'custom', 'scope_parameter' => 'tenant']);
    DynamicThrottle::scope('tenant', $resolver);

    expect(Health::check('scopes'))->toBe(['ok', 'Every custom scope the rules and exemptions use is registered: tenant.']);
})->with([
    'a closure' => [fn (): Closure => fn (Request $request): string => 'acme'],
    'a ScopeResolver class' => [PlanFromConfig::class],
]);

it('AC-9: errs when an enforcing, fail-closed rule uses an unregistered scope, and warns for a dry-run one', function (string $mode, string $status): void {
    Throttling::rule(['name' => 'Per tenant', 'scope' => 'custom', 'scope_parameter' => 'tenant', 'on_failure' => 'closed', 'mode' => $mode]);

    expect(Health::check('scopes')[0])->toBe($status);
})->with([
    'enforcing' => ['enforce', 'error'],
    'in dry run' => ['dry_run', 'warning'],
]);

it('AC-10: is ok without a custom scope, and skipped without a manifest', function (): void {
    $without = Health::check('scopes');
    Cache::store('health')->forget(app(KeyBuilder::class)->manifest());

    expect($without)->toBe(['ok', 'No rule or exemption uses a custom scope.'])
        ->and(Health::check('scopes'))->toBe(['skipped', 'The manifest is missing, so the rules are unknown.']);
});

it('AC-11: lists database, manifest, compile and scopes in order, and api/health gives them too', function (): void {
    DashboardApi::prepare();

    $ids = array_column(DashboardApi::send($this, 'GET', 'health', [], DashboardApi::viewer())->json('checks'), 'check');

    expect(array_keys(Health::checks()))->toBe(Health::CHECKS)
        ->and(array_slice(Health::CHECKS, 12, 4))->toBe(['database', 'manifest', 'compile', 'scopes'])
        ->and($ids)->toBe(Health::CHECKS);
});
