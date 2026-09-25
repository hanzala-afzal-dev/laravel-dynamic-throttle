<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Exemptions;

use Afzal\DynamicThrottle\Engine\Contracts\ExemptionSubjects;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Manifest\CompiledExemption;

/**
 * The manifest's exemptions, grouped by rule and subject when it loads (SPEC-009,
 * ADR-0023), so a request's check costs one lookup per subject and IP prefix
 * length, however many exemptions there are.
 */
final readonly class ExemptionSet
{
    /** What global exemptions are grouped under: no rule id is empty. */
    private const string EVERY_RULE = '';

    /**
     * Each rule's exemptions, and the global ones under EVERY_RULE, as positions in
     * $exemptions, in ascending order:
     * - ip: by address length (4 or 16 bytes), then prefix length, then the network's bytes
     * - user: by the user's hash
     * - custom: by the scope's name, then the value's hash
     *
     * @var array<array-key, array{ip?: array<int, array<int, array<array-key, non-empty-list<int>>>>, user?: array<array-key, non-empty-list<int>>, custom?: array<array-key, array<array-key, non-empty-list<int>>>}>
     */
    private array $groups;

    /** @var array<array-key, list<string>> the custom scopes each rule's check asks, with the global ones, by name */
    private array $scopes;

    /**
     * @param  list<CompiledExemption>  $exemptions  in id order, as the compiler writes them,
     *                                               so the first match is the lowest id
     */
    public function __construct(
        private array $exemptions = [],
    ) {
        $groups = [];

        foreach ($exemptions as $position => $exemption) {
            $rule = $exemption->rule ?? self::EVERY_RULE;

            if ($exemption->range instanceof IpRange) {
                $groups[$rule]['ip'][strlen($exemption->range->network)][$exemption->range->prefixLength][$exemption->range->network][] = $position;
            } elseif ($exemption->subject === ExemptionSubject::User) {
                $groups[$rule]['user'][$exemption->match][] = $position;
            } else {
                $groups[$rule]['custom'][(string) $exemption->parameter][$exemption->match][] = $position;
            }
        }

        $everyRule = array_keys($groups[self::EVERY_RULE]['custom'] ?? []);
        $scopes = [];

        foreach ($groups as $rule => $group) {
            // Scope names can be digits, which PHP turns into integer keys.
            $names = array_map(strval(...), array_unique([...array_keys($group['custom'] ?? []), ...$everyRule]));
            sort($names, SORT_STRING);
            $scopes[$rule] = $names;
        }

        $this->groups = $groups;
        $this->scopes = $scopes;
    }

    /**
     * @return list<CompiledExemption>
     */
    public function all(): array
    {
        return $this->exemptions;
    }

    /**
     * The id of the exemption that frees the request from the rule, or null. The
     * checks run in order, IP, then user, then custom scopes by name, and stop at
     * the first match. A check that fails counts as no match (SPEC-009 decision 2).
     *
     * @throws IdentityUnavailableException the first failed check's, when no check matched
     */
    public function match(string $ruleId, int $nowMicroseconds, ExemptionSubjects $subjects): ?string
    {
        $own = $this->groups[$ruleId] ?? [];
        $everyRule = $this->groups[self::EVERY_RULE] ?? [];

        if ($own === [] && $everyRule === []) {
            return null;
        }

        $failure = null;

        try {
            $position = isset($own['ip']) || isset($everyRule['ip'])
                ? $this->matchIp($own['ip'] ?? [], $everyRule['ip'] ?? [], IpRange::address($subjects->ip()), $nowMicroseconds)
                : null;
        } catch (IdentityUnavailableException $exception) {
            [$position, $failure] = [null, $exception];
        }

        if ($position === null && (isset($own['user']) || isset($everyRule['user']))) {
            try {
                $position = $this->matchHash($own['user'] ?? [], $everyRule['user'] ?? [], $subjects->user(), $nowMicroseconds);
            } catch (IdentityUnavailableException $exception) {
                $failure ??= $exception;
            }
        }

        foreach ($position === null ? $this->scopes[$ruleId] ?? $this->scopes[self::EVERY_RULE] ?? [] : [] as $scope) {
            try {
                $position = $this->matchHash($own['custom'][$scope] ?? [], $everyRule['custom'][$scope] ?? [], $subjects->custom($scope), $nowMicroseconds);
            } catch (IdentityUnavailableException $exception) {
                $failure ??= $exception;
            }

            if ($position !== null) {
                break;
            }
        }

        if ($position !== null) {
            return $this->exemptions[$position]->id;
        }

        return $failure instanceof IdentityUnavailableException ? throw $failure : null;
    }

    /**
     * The lowest active position among the ranges, of the rule and global, that
     * contain the address: one lookup per prefix length in use.
     *
     * @param  array<int, array<int, array<array-key, non-empty-list<int>>>>  $own
     * @param  array<int, array<int, array<array-key, non-empty-list<int>>>>  $everyRule
     */
    private function matchIp(array $own, array $everyRule, ?string $address, int $now): ?int
    {
        if ($address === null) {
            return null;
        }

        $lowest = null;

        foreach ([$own[strlen($address)] ?? [], $everyRule[strlen($address)] ?? []] as $byPrefixLength) {
            foreach ($byPrefixLength as $prefixLength => $networks) {
                $position = $this->active($networks[IpRange::cut($address, $prefixLength)] ?? [], $now);
                $lowest = $position === null || ($lowest !== null && $lowest < $position) ? $lowest : $position;
            }
        }

        return $lowest;
    }

    /**
     * The lowest active position among the rule's and the global exemptions of this hash.
     *
     * @param  array<array-key, non-empty-list<int>>  $own
     * @param  array<array-key, non-empty-list<int>>  $everyRule
     */
    private function matchHash(array $own, array $everyRule, ?string $hash, int $now): ?int
    {
        if ($hash === null) {
            return null;
        }

        $mine = $this->active($own[$hash] ?? [], $now);
        $global = $this->active($everyRule[$hash] ?? [], $now);

        return $mine === null || ($global !== null && $global < $mine) ? $global : $mine;
    }

    /**
     * The first position whose exemption has not expired. From the second it expires,
     * an exemption is ignored, even before the next compile (SPEC-009 AC-17).
     *
     * @param  list<int>  $positions  ascending
     */
    private function active(array $positions, int $now): ?int
    {
        foreach ($positions as $position) {
            $expiresAt = $this->exemptions[$position]->expiresAt;

            if ($expiresAt === null || $now < $expiresAt * 1_000_000) {
                return $position;
            }
        }

        return null;
    }
}
