<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Stacking\EvaluationOutcome;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Tests\Support\Identities;
use Illuminate\Http\Request;

/*
| SPEC-008 AC-16 (ADR-0020 point 8): per-tenant limits are identities, not
| rules. One rule with a custom "tenant" scope counts each tenant separately.
*/

it('AC-16: counts each tenant separately under one rule', function (): void {
    DynamicThrottle::scope('tenant', fn (Request $request): ?string => $request->headers->get('X-Tenant'));
    $rule = Identities::rule('custom', parameter: 'tenant', max: 2);
    $evaluator = new RuleEvaluator(Identities::store());
    $request = fn (string $tenant): EvaluationOutcome => $evaluator
        ->evaluate([$rule], Identities::resolver(Identities::request('203.0.113.7', ['X-Tenant' => $tenant])))
        ->outcome;

    expect([$request('acme'), $request('acme'), $request('acme'), $request('globex')])->toBe([
        EvaluationOutcome::Allowed,
        EvaluationOutcome::Allowed,
        EvaluationOutcome::Rejected,
        EvaluationOutcome::Allowed,
    ]);
});
