<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use Afzal\DynamicThrottle\Engine\Exemptions\IpRange;
use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use InvalidArgumentException;

/**
 * One exemption as the request path sees it (SPEC-009 AC-8). The constructor
 * checks every invariant, so the compiler and the codec reject the same values.
 */
final readonly class CompiledExemption
{
    /** The stored field names, in the order the manifest writes them. */
    public const array FIELDS = ['id', 'rule', 'subject', 'parameter', 'match', 'expires_at'];

    /** FIELDS as sort() orders them, to check an exemption whose fields come in another order. */
    private const array SORTED_FIELDS = ['expires_at', 'id', 'match', 'parameter', 'rule', 'subject'];

    /** Ids, rule ids and custom scope names share the alphabet counters are keyed by. */
    private const string NAME = '/\A[a-z0-9_-]{1,64}\z/';

    /** An ip exemption's range, parsed once when the manifest loads; null for the other subjects. */
    public ?IpRange $range;

    /**
     * @param  ?string  $rule  the rule's id, or null for every rule
     * @param  ?string  $parameter  the custom scope's name; null for the other subjects
     * @param  string  $match  a normalized range for ip, a value's hash (IdentityHasher) for user and custom
     * @param  ?int  $expiresAt  Unix seconds, or null for never
     */
    public function __construct(
        public string $id,
        public ?string $rule,
        public ExemptionSubject $subject,
        public ?string $parameter,
        public string $match,
        public ?int $expiresAt,
    ) {
        if (preg_match(self::NAME, $id) !== 1) {
            throw new InvalidArgumentException("id must be 1 to 64 characters from a-z, 0-9, \"_\" and \"-\", got \"{$id}\".");
        }

        if ($rule !== null && preg_match(self::NAME, $rule) !== 1) {
            throw new InvalidArgumentException("rule must be a rule id or null, got \"{$rule}\".");
        }

        if ($subject === ExemptionSubject::Custom) {
            if (preg_match(self::NAME, (string) $parameter) !== 1) {
                throw new InvalidArgumentException('parameter must be a custom scope\'s name, 1 to 64 characters from a-z, 0-9, "_" and "-", got '.self::describe($parameter).'.');
            }
        } elseif ($parameter !== null) {
            throw new InvalidArgumentException("parameter must be null for the {$subject->value} subject, got ".self::describe($parameter).'.');
        }

        if ($subject === ExemptionSubject::Ip) {
            $this->range = $this->normalizedRange($match);
        } elseif (preg_match('/\A[0-9a-f]{64}\z/', $match) === 1) {
            // A user or custom exemption holds its value's hash, never the value (ADR-0024).
            $this->range = null;
        } else {
            throw new InvalidArgumentException('match must be 64 lowercase hexadecimal characters, a value\'s hash.');
        }

        if ($expiresAt !== null && $expiresAt < 1) {
            throw new InvalidArgumentException("expires_at must be a positive Unix time or null, got {$expiresAt}.");
        }
    }

    /**
     * From the manifest's JSON: exactly FIELDS, with exact types. Every manifest load
     * runs this for every exemption, so the checks are inline.
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException naming the first bad field
     */
    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);

        if ($keys !== self::FIELDS) {
            sort($keys);

            if ($keys !== self::SORTED_FIELDS) {
                throw new InvalidArgumentException('An exemption must have exactly the fields '.implode(', ', self::FIELDS).'.');
            }
        }

        [
            'id' => $id, 'rule' => $rule, 'subject' => $subject,
            'parameter' => $parameter, 'match' => $match, 'expires_at' => $expiresAt,
        ] = $data;

        return new self(
            id: is_string($id) ? $id : throw self::wrongType('id', 'a string', $id),
            rule: $rule === null || is_string($rule) ? $rule : throw self::wrongType('rule', 'a string or null', $rule),
            subject: (is_string($subject) ? ExemptionSubject::tryFrom($subject) : null)
                ?? throw new InvalidArgumentException('subject must be one of ip, user, custom, got '.self::describe($subject).'.'),
            parameter: $parameter === null || is_string($parameter) ? $parameter : throw self::wrongType('parameter', 'a string or null', $parameter),
            match: is_string($match) ? $match : throw self::wrongType('match', 'a string', $match),
            expiresAt: $expiresAt === null || is_int($expiresAt) ? $expiresAt : throw self::wrongType('expires_at', 'an integer or null', $expiresAt),
        );
    }

    /**
     * For the manifest's JSON, in FIELDS order.
     *
     * @return array{id: string, rule: ?string, subject: string, parameter: ?string, match: string, expires_at: ?int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'rule' => $this->rule,
            'subject' => $this->subject->value,
            'parameter' => $this->parameter,
            'match' => $this->match,
            'expires_at' => $this->expiresAt,
        ];
    }

    /**
     * The range an ip exemption matches, which the compiler writes normalized.
     */
    private function normalizedRange(string $match): IpRange
    {
        try {
            $range = IpRange::parse($match);
        } catch (InvalidArgumentException) {
            $range = null;
        }

        return $range instanceof IpRange && (string) $range === $match
            ? $range
            : throw new InvalidArgumentException("match must be a normalized IP range, such as \"10.0.0.0/8\", got \"{$match}\".");
    }

    private static function wrongType(string $field, string $type, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException("{$field} must be {$type}, got ".get_debug_type($value).'.');
    }

    private static function describe(mixed $value): string
    {
        return is_string($value) ? "\"{$value}\"" : get_debug_type($value);
    }
}
