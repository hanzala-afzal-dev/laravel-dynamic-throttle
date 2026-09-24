<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Stacking\EvaluationOutcome;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\Identities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

it('AC-16: counts user 42 of two tenants apart with the multi-database recipe (decision 7)', function (): void {
    /** @var ?string $tenant the current tenant's key */
    $tenant = 'acme';
    // SPEC-008's recipe, with the current tenant in a variable instead of tenant().
    DynamicThrottle::scope('tenant-user', function () use (&$tenant): ?string {
        $guard = Auth::guard();

        if ($tenant === null || ! $guard->hasUser()) {
            return null;
        }

        $user = $guard->user();

        return json_encode([$tenant, $user::class, $user->getAuthIdentifier()], JSON_THROW_ON_ERROR);
    });
    $rule = Identities::rule('custom', parameter: 'tenant-user', max: 1);
    $skipping = Identities::rule('custom', missing: 'skip', parameter: 'tenant-user');
    $evaluator = new RuleEvaluator(Identities::store());
    $request = fn (): EvaluationOutcome => $evaluator->evaluate([$rule], Identities::resolver(Identities::request()))->outcome;

    $guest = Identities::of(Identities::request(), $skipping);
    $this->actingAs(new TestUser(['id' => 42]));
    $acme = [$request(), $request()];
    $tenant = 'globex';
    $globex = $request();
    $tenant = null;

    expect([$guest, Identities::of(Identities::request(), $skipping)])->toBe([null, null])
        ->and($acme)->toBe([EvaluationOutcome::Allowed, EvaluationOutcome::Rejected])
        ->and($globex)->toBe(EvaluationOutcome::Allowed);
});
