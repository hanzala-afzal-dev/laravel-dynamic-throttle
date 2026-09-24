<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Stacking\EvaluationOutcome;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\Identities;
use Illuminate\Auth\GenericUser;
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

/**
 * SPEC-008's multi-database recipe, with the current tenant from a closure
 * instead of tenant().
 *
 * @param  Closure(): ?string  $tenant
 */
function registerTenantUserScope(Closure $tenant): void
{
    DynamicThrottle::scope('tenant-user', function () use ($tenant): ?string {
        $guard = Auth::guard();

        // Like the user scope with scopes.user.guards unset: only a user that auth has already loaded.
        if ($tenant() === null || ! $guard->hasUser()) {
            return null;
        }

        $user = $guard->user();

        // serialize() keeps the parts apart, whatever bytes they contain.
        return serialize([(string) $tenant(), $user::class, (string) $user->getAuthIdentifier()]);
    });
}

it('AC-16: counts users of two tenants apart with the multi-database recipe (decision 7)', function (): void {
    /** @var ?string $tenant the current tenant's key */
    $tenant = 'acme';
    registerTenantUserScope(function () use (&$tenant): ?string {
        return $tenant;
    });
    $rule = Identities::rule('custom', parameter: 'tenant-user', max: 1);
    $skipping = Identities::rule('custom', missing: 'skip', parameter: 'tenant-user');
    $evaluator = new RuleEvaluator(Identities::store());
    $request = fn (): EvaluationOutcome => $evaluator->evaluate([$rule], Identities::resolver(Identities::request()))->outcome;

    $guest = Identities::of(Identities::request(), $skipping);
    $this->actingAs(new TestUser(['id' => 42]));
    $acme42 = [$request(), $request()];
    $this->actingAs(new TestUser(['id' => 43]));
    $acme43 = $request();
    $tenant = 'globex';
    $this->actingAs(new TestUser(['id' => 42]));
    $globex42 = $request();
    $tenant = null;

    expect([$guest, Identities::of(Identities::request(), $skipping)])->toBe([null, null])
        ->and($acme42)->toBe([EvaluationOutcome::Allowed, EvaluationOutcome::Rejected])
        ->and($acme43)->toBe(EvaluationOutcome::Allowed)
        ->and($globex42)->toBe(EvaluationOutcome::Allowed);
});

it('AC-16: counts users whose ids are only Stringable by their string form in the recipe, like the user scope', function (): void {
    registerTenantUserScope(fn (): string => 'acme');
    $rule = Identities::rule('custom', parameter: 'tenant-user', max: 1);
    $evaluator = new RuleEvaluator(Identities::store());
    $id = fn (string $value): Stringable => new readonly class($value) implements Stringable
    {
        public function __construct(private string $value) {}

        public function __toString(): string
        {
            return $this->value;
        }
    };

    $this->actingAs(new GenericUser(['id' => $id('u-1')]));
    $first = $evaluator->evaluate([$rule], Identities::resolver(Identities::request()))->outcome;
    $this->actingAs(new GenericUser(['id' => $id('u-2')]));
    $second = $evaluator->evaluate([$rule], Identities::resolver(Identities::request()))->outcome;

    expect([$first, $second])->toBe([EvaluationOutcome::Allowed, EvaluationOutcome::Allowed]);
});
