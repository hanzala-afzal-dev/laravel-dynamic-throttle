<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Exemptions;

use Afzal\DynamicThrottle\Engine\Contracts\ExemptionSubjects;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Manifest\CompiledExemption;
use Afzal\DynamicThrottle\Manifest\InvalidManifest;
use Afzal\DynamicThrottle\Manifest\ManifestExemptions;
use InvalidArgumentException;

/**
 * The manifest's exemptions, grouped by rule and subject when it loads (SPEC-009,
 * ADR-0023), so a request's check costs one lookup per subject and IP prefix
 * length, however many exemptions there are. From L1's groups, or from the store's
 * fields, an exemption is built only when a check reaches it (SPEC-028 AC-16 and
 * AC-17, as amended for #194 and #195).
 *
 * @phpstan-type Groups array<array-key, array{ip?: array<int, array<int, array<array-key, non-empty-list<int>>>>, user?: array<array-key, non-empty-list<int>>, custom?: array<array-key, array<array-key, non-empty-list<int>>>}>
 */
final readonly class ExemptionSet
{
    /** What global exemptions are grouped under: no rule id is empty, and any other string that is no rule id would do. */
    private const string EVERY_RULE = ''; // @pest-mutate-ignore: EmptyStringToNotEmpty

    /**
     * Each rule's exemptions, and the global ones under EVERY_RULE, as positions in
     * $exemptions, in ascending order:
     * - ip: by address length (4 or 16 bytes), then prefix length, then the network's bytes
     * - user: by the user's hash
     * - custom: by the scope's name, then the value's hash
     *
     * @var Groups
     */
    private array $groups;

    /** @var array<array-key, list<string>> the custom scopes each rule's check asks, with the global ones, by name */
    private array $scopes;

    /** The exemptions by position, built when a check reaches them unless they came built. */
    private ManifestExemptions $exemptions;

    /**
     * @param  list<CompiledExemption>|ManifestExemptions  $exemptions  in id order, as the compiler writes them,
     *                                                                  so the first match is the lowest id
     * @param  Groups|null  $groups  their groups, already checked: only fromParts() and fromArrays() pass them
     */
    public function __construct(array|ManifestExemptions $exemptions = [], ?array $groups = null)
    {
        $list = is_array($exemptions) ? ManifestExemptions::of($exemptions) : $exemptions;
        $this->groups = $groups ?? $this->groupCompiled($list->all());
        $this->scopes = $this->scopesOf($this->groups);
        $this->exemptions = $list;
    }

    /**
     * From L1: the groups toArray() gave, checked against the exemptions' fields, which
     * are built when a check reaches them (SPEC-028 AC-9 and AC-16, as amended).
     *
     * @param  array<array-key, mixed>  $groups
     * @param  list<array<array-key, mixed>>  $fields
     *
     * @throws InvalidArgumentException naming the first part of the groups that is wrong
     */
    public static function fromParts(array $groups, array $fields): self
    {
        self::checkGroups($groups, count($fields));

        return new self(ManifestExemptions::fromArrays($fields), $groups);
    }

    /**
     * From the store's read path: the exemptions grouped from their fields, each one
     * built when a check reaches it (SPEC-028 AC-17, as amended).
     *
     * @param  list<mixed>  $fields
     *
     * @throws InvalidManifest for an exemption whose fields cannot be grouped
     */
    public static function fromArrays(array $fields): self
    {
        /** @var Groups $groups */
        $groups = [];
        $checked = [];

        foreach ($fields as $position => $field) {
            if (! is_array($field)) {
                throw new InvalidManifest("Exemption #{$position} in the manifest must be a JSON object.");
            }

            $rule = $field['rule'] ?? null;
            $match = $field['match'] ?? null;
            $parameter = $field['parameter'] ?? null;

            if (($rule !== null && ! is_string($rule)) || ! is_string($match)) {
                throw new InvalidManifest("Exemption #{$position} in the manifest cannot be grouped: its rule must be a string or null, and its match a string.");
            }

            $rule ??= self::EVERY_RULE;

            match ($field['subject'] ?? null) {
                'ip' => self::groupRange($groups, $rule, $match, $position),
                'user' => $groups[$rule]['user'][$match][] = $position,
                'custom' => is_string($parameter)
                    ? $groups[$rule]['custom'][$parameter][$match][] = $position
                    : throw new InvalidManifest("Exemption #{$position} in the manifest cannot be grouped: a custom exemption names its scope."),
                default => throw new InvalidManifest("Exemption #{$position} in the manifest cannot be grouped: its subject must be ip, user or custom."),
            };

            $checked[] = $field;
        }

        return new self(ManifestExemptions::fromArrays($checked), $groups);
    }

    /**
     * The groups, as plain values for L1, which fromParts() takes back (SPEC-028 AC-16).
     *
     * @return Groups
     */
    public function toArray(): array
    {
        return $this->groups;
    }

    /**
     * Every exemption, in manifest order. From fields, the ones not read yet are built now.
     *
     * @return list<CompiledExemption>
     *
     * @throws InvalidManifest for fields that are not an exemption
     */
    public function all(): array
    {
        return $this->exemptions->all();
    }

    /**
     * Each exemption's fields, for L1, without building any.
     *
     * @return list<array<array-key, mixed>>
     */
    public function arrays(): array
    {
        return $this->exemptions->arrays();
    }

    /**
     * How many exemptions there are, without building any (SPEC-028 AC-19).
     */
    public function count(): int
    {
        return $this->exemptions->count();
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

        // A fast path: without it, no check would run, and null would come back anyway.
        if ($own === [] && $everyRule === []) {
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
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
            return $this->exemptions->get($position)->id;
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
                // Positions are unique, so < and <= agree.
                $lowest = $position === null || ($lowest !== null && $lowest < $position) ? $lowest : $position; // @pest-mutate-ignore: SmallerToSmallerOrEqual
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
        // A fast path: no hash is empty, so looking one up would find nothing anyway.
        if ($hash === null) {
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $mine = $this->active($own[$hash] ?? [], $now);
        $global = $this->active($everyRule[$hash] ?? [], $now);

        // A position is the rule's or global, never both, so < and <= agree.
        return $mine === null || ($global !== null && $global < $mine) ? $global : $mine; // @pest-mutate-ignore: SmallerToSmallerOrEqual
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
            $expiresAt = $this->exemptions->get($position)->expiresAt;

            if ($expiresAt === null || $now < $expiresAt * 1_000_000) {
                return $position;
            }
        }

        return null;
    }

    /**
     * Each exemption's position, under its rule, its subject and its key.
     *
     * @param  list<CompiledExemption>  $exemptions
     * @return Groups
     */
    private function groupCompiled(array $exemptions): array
    {
        $groups = [];

        foreach ($exemptions as $position => $exemption) {
            $rule = $exemption->rule ?? self::EVERY_RULE;

            // Only ip exemptions have a range, and only custom ones name a scope (CompiledExemption).
            if ($exemption->range instanceof IpRange) {
                $groups[$rule]['ip'][strlen($exemption->range->network)][$exemption->range->prefixLength][$exemption->range->network][] = $position;
            } elseif ($exemption->parameter === null) {
                $groups[$rule]['user'][$exemption->match][] = $position;
            } else {
                $groups[$rule]['custom'][$exemption->parameter][$exemption->match][] = $position;
            }
        }

        return $groups;
    }

    /**
     * Groups an ip exemption by its range's network, which the range's fields must parse into.
     *
     * @param  Groups  $groups
     *
     * @throws InvalidManifest for a match that is no range
     */
    private static function groupRange(array &$groups, string $rule, string $match, int $position): void
    {
        try {
            $range = IpRange::parse($match);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidManifest("Exemption #{$position} in the manifest cannot be grouped: ".$exception->getMessage(), $exception->getCode(), previous: $exception);
        }

        $groups[$rule]['ip'][strlen($range->network)][$range->prefixLength][$range->network][] = $position;
    }

    /**
     * The custom scopes each rule's check asks, with the global ones, by name.
     *
     * @param  Groups  $groups
     * @return array<array-key, list<string>>
     */
    private function scopesOf(array $groups): array
    {
        $everyRule = array_keys($groups[self::EVERY_RULE]['custom'] ?? []);
        $scopes = [];

        foreach ($groups as $rule => $group) {
            // Scope names can be digits, which PHP turns into integer keys.
            $names = array_map(strval(...), array_unique([...array_keys($group['custom'] ?? []), ...$everyRule]));
            sort($names, SORT_STRING);
            $scopes[$rule] = $names;
        }

        return $scopes;
    }

    /**
     * Checks the groups toArray() gave: the subjects it knows, keys and positions of the
     * right types, and each of the exemptions held exactly once, in order.
     *
     * @param  array<array-key, mixed>  $groups
     *
     * @phpstan-assert Groups $groups
     *
     * @throws InvalidArgumentException naming the first part that is wrong
     */
    private static function checkGroups(array $groups, int $count): void
    {
        $held = [];

        foreach ($groups as $rule => $group) {
            if (! is_array($group)) {
                throw new InvalidArgumentException("The groups of rule \"{$rule}\" must be an array.");
            }

            foreach ($group as $subject => $keys) {
                match ($subject) {
                    'ip' => self::checkIpGroups($keys, $count, $held),
                    'user' => self::checkHashGroups($keys, $count, $held),
                    'custom' => self::checkScopeGroups($keys, $count, $held),
                    default => throw new InvalidArgumentException('A group\'s subject must be ip, user or custom, got "'.$subject.'".'),
                };
            }
        }

        if (count($held) !== $count) {
            throw new InvalidArgumentException("The groups must hold each of the {$count} exemptions exactly once.");
        }
    }

    /**
     * Checks ip groups: by address length, 4 or 16 bytes, then by a prefix length the
     * address can have, then by a network of that length.
     *
     * @param  array<int, true>  $held  the positions held so far
     *
     * @throws InvalidArgumentException
     */
    private static function checkIpGroups(mixed $byLength, int $count, array &$held): void
    {
        foreach (is_array($byLength) ? $byLength : throw new InvalidArgumentException('The ip groups must be an array.') as $length => $byPrefix) {
            if ($length !== 4 && $length !== 16) {
                throw new InvalidArgumentException("An address is 4 or 16 bytes long, got {$length}.");
            }

            foreach (is_array($byPrefix) ? $byPrefix : throw new InvalidArgumentException('The ip groups must be arrays.') as $prefixLength => $networks) {
                if (! is_int($prefixLength) || $prefixLength < 0 || $prefixLength > $length * 8) {
                    throw new InvalidArgumentException("A prefix length of a {$length}-byte address is from 0 to ".($length * 8).", got {$prefixLength}.");
                }

                foreach (is_array($networks) ? $networks : throw new InvalidArgumentException('The ip groups must be arrays.') as $network => $positions) {
                    if (! is_string($network) || strlen($network) !== $length) {
                        throw new InvalidArgumentException("A network of a {$length}-byte address must be {$length} bytes long.");
                    }

                    self::checkPositions($positions, $count, $held);
                }
            }
        }
    }

    /**
     * Checks hash groups: each hash's positions.
     *
     * @param  array<int, true>  $held  the positions held so far
     *
     * @throws InvalidArgumentException
     */
    private static function checkHashGroups(mixed $byHash, int $count, array &$held): void
    {
        foreach (is_array($byHash) ? $byHash : throw new InvalidArgumentException('The user and custom groups must be arrays.') as $positions) {
            self::checkPositions($positions, $count, $held);
        }
    }

    /**
     * Checks custom groups: by the scope's name, then by hash.
     *
     * @param  array<int, true>  $held  the positions held so far
     *
     * @throws InvalidArgumentException
     */
    private static function checkScopeGroups(mixed $byScope, int $count, array &$held): void
    {
        foreach (is_array($byScope) ? $byScope : throw new InvalidArgumentException('The custom groups must be an array.') as $byHash) {
            self::checkHashGroups($byHash, $count, $held);
        }
    }

    /**
     * Positions in ascending order, each an exemption's, and none held before, so the
     * first match is the lowest id and each exemption is in one group only.
     *
     * @param  array<int, true>  $held  the positions held so far
     *
     * @throws InvalidArgumentException
     */
    private static function checkPositions(mixed $positions, int $count, array &$held): void
    {
        if (! is_array($positions) || $positions === [] || ! array_is_list($positions)) {
            throw new InvalidArgumentException('A group\'s positions must be a list.');
        }

        $previous = -1;

        foreach ($positions as $position) {
            if (! is_int($position) || $position <= $previous || $position >= $count || isset($held[$position])) {
                throw new InvalidArgumentException("A group holds a position that is not one of the {$count} exemptions, in order, once.");
            }

            $held[$position] = true;
            $previous = $position;
        }
    }
}
