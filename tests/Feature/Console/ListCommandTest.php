<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;

/*
| SPEC-013 AC-17: dynamic-throttle:list shows every rule in the database,
| disabled ones too, in the manifest's order (priority, then id), with the ids
| that disable-rule and enable-rule take.
*/

beforeEach(function (): void {
    ManifestRepository::flushState();
    RuleSchema::migrate();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-17: lists every rule, disabled ones too, by priority, then id', function (): void {
    $api = Rule::create(RuleSchema::attributes(['name' => 'API', 'priority' => 100]));
    $login = Rule::create(RuleSchema::attributes([
        'name' => 'Login',
        'method' => RequestMethod::Post,
        'pattern' => 'login',
        'max_attempts' => 5,
        'window_seconds' => 300,
        'priority' => 10,
        'on_failure' => FailurePolicy::Closed,
    ]));
    $tenants = Rule::create(RuleSchema::attributes([
        'name' => 'Tenants',
        'scope' => Scope::Custom,
        'scope_parameter' => 'tenant',
        'priority' => 100,
        'mode' => RuleMode::DryRun,
        'enabled' => false,
    ]));

    $this->artisan('dynamic-throttle:list')
        ->expectsTable(
            ['ID', 'Name', 'Method', 'Pattern', 'Scope', 'Limit', 'Mode', 'On failure', 'Enabled'],
            [
                [(string) $login->id, 'Login', 'POST', 'login', 'ip', '5 per 300s', 'enforce', 'closed', 'yes'],
                [(string) $api->id, 'API', '*', 'api/*', 'ip', '60 per 60s', 'enforce', 'open', 'yes'],
                [(string) $tenants->id, 'Tenants', '*', 'api/*', 'custom:tenant', '60 per 60s', 'dry_run', 'open', 'no'],
            ],
        )
        ->assertExitCode(0);
});

it('AC-17: says there is no rule yet', function (): void {
    $this->artisan('dynamic-throttle:list')
        ->expectsOutputToContain('There are no rules yet.')
        ->assertExitCode(0);
});
