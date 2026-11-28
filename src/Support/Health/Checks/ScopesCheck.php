<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\DynamicThrottle;
use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;

/**
 * Custom scopes that the manifest's rules and exemptions use, and that no resolver
 * is registered for (SPEC-037, issue #205). A rule that uses one fails to find the
 * client on every request it matches, and an exemption exempts nobody. An error when
 * an enforcing, fail-closed rule uses one, since each request it matches answers 503
 * (decision 6). Only the resolvers this process registered count (decision 7).
 *
 * @internal
 */
final readonly class ScopesCheck implements Check
{
    private const string ADVICE = 'A rule that uses one fails to find the client on every request it matches: the error is reported, and the request goes through, or answers 503 on a fail-closed rule. '
        .'An exemption that uses one exempts nobody. Register them with DynamicThrottle::scope() in a service provider\'s boot().';

    public function id(): string
    {
        return 'scopes';
    }

    public function run(HealthContext $context): CheckResult
    {
        $read = $context->manifest();

        if (! $read->manifest instanceof Manifest) {
            return CheckResult::skipped($this->id(), (string) $read->problem());
        }

        $scopes = $context->container->make(DynamicThrottle::class);
        /** @var array<string, true> $used every custom scope the manifest uses */
        $used = [];
        /** @var array<string, list<string>> $unregistered what uses each scope without a resolver */
        $unregistered = [];
        $closed = false;

        foreach ($read->manifest->rules() as $rule) {
            if ($rule->scope !== Scope::Custom || $rule->scopeParameter === null) {
                continue;
            }

            $used[$rule->scopeParameter] = true;

            if ($scopes->scopeResolver($rule->scopeParameter) === null) {
                $unregistered[$rule->scopeParameter][] = HealthContext::rule($rule);
                $closed = $closed || ($rule->mode === RuleMode::Enforce && $rule->onFailure === FailurePolicy::Closed);
            }
        }

        foreach ($read->manifest->exemptions->all() as $exemption) {
            if ($exemption->subject !== ExemptionSubject::Custom || $exemption->parameter === null) {
                continue;
            }

            $used[$exemption->parameter] = true;

            if ($scopes->scopeResolver($exemption->parameter) === null) {
                $unregistered[$exemption->parameter][] = "exemption {$exemption->id}";
            }
        }

        if ($used === []) {
            return CheckResult::ok($this->id(), 'No rule or exemption uses a custom scope.');
        }

        if ($unregistered === []) {
            $names = array_map(strval(...), array_keys($used));
            sort($names);

            return CheckResult::ok($this->id(), 'Every custom scope the rules and exemptions use is registered: '.implode(', ', $names).'.');
        }

        ksort($unregistered);
        $count = count($unregistered);
        $named = [];

        foreach ($unregistered as $name => $users) {
            $named[] = "{$name}, for ".HealthContext::listed($users, '; ').'.';
        }

        $message = "{$count} custom ".($count === 1 ? 'scope is' : 'scopes are').' not registered: '.implode(' ', $named).' '.self::ADVICE;

        return $closed ? CheckResult::error($this->id(), $message) : CheckResult::warning($this->id(), $message);
    }
}
