<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;

/*
| SPEC-005 AC-12: the values are stored in the database, so changing one
| is a schema change (ADR-0017).
*/

it('AC-12: has exactly these cases and values', function (string $enum, array $cases): void {
    /** @var class-string<BackedEnum> $enum */
    $actual = [];

    foreach ($enum::cases() as $case) {
        $actual[$case->name] = $case->value;
    }

    expect($actual)->toBe($cases);
})->with([
    'RequestMethod' => [RequestMethod::class, [
        'Any' => '*', 'Get' => 'GET', 'Head' => 'HEAD', 'Post' => 'POST',
        'Put' => 'PUT', 'Patch' => 'PATCH', 'Delete' => 'DELETE', 'Options' => 'OPTIONS',
    ]],
    'Scope' => [Scope::class, [
        'Ip' => 'ip', 'User' => 'user', 'ApiToken' => 'api_token',
        'Header' => 'header', 'Global' => 'global', 'Custom' => 'custom',
    ]],
    'RuleMode' => [RuleMode::class, ['Enforce' => 'enforce', 'DryRun' => 'dry_run']],
    'FailurePolicy' => [FailurePolicy::class, ['Open' => 'open', 'Closed' => 'closed']],
    'MissingIdentity' => [MissingIdentity::class, ['FallBackToIp' => 'ip', 'Skip' => 'skip']],
]);
