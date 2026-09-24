<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use Afzal\DynamicThrottle\Engine\Internal\AttemptGuard;
use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use BackedEnum;
use InvalidArgumentException;

/**
 * One rule as the request path sees it (SPEC-006 AC-2). The constructor checks
 * every invariant, so the compiler and the codec reject the same bad values.
 */
final readonly class CompiledRule
{
    /** The stored field names, in the order the manifest writes them. */
    public const array FIELDS = [
        'id', 'name', 'method', 'pattern', 'scope', 'scope_parameter', 'max_attempts',
        'window_seconds', 'priority', 'mode', 'on_failure', 'on_missing_identity', 'response_override',
    ];

    /**
     * @param  array<array-key, mixed>|null  $responseOverride  a JSON object
     */
    public function __construct(
        public string $id,
        public string $name,
        public RequestMethod $method,
        public string $pattern,
        public Scope $scope,
        public ?string $scopeParameter,
        public int $maxAttempts,
        public int $windowSeconds,
        public int $priority,
        public RuleMode $mode,
        public FailurePolicy $onFailure,
        public MissingIdentity $onMissingIdentity,
        public ?array $responseOverride,
    ) {
        // The rule ids CounterKey accepts, because counters are keyed by them.
        if (preg_match('/\A[a-z0-9_-]{1,64}\z/', $id) !== 1) {
            throw new InvalidArgumentException("id must be 1 to 64 characters from a-z, 0-9, \"_\" and \"-\", got \"{$id}\".");
        }

        if ($pattern === '' || strlen($pattern) > 255) {
            throw new InvalidArgumentException('pattern must be 1 to 255 characters, got '.strlen($pattern).'.');
        }

        if (in_array($scope, [Scope::Header, Scope::Custom], true) && ($scopeParameter === null || $scopeParameter === '')) {
            throw new InvalidArgumentException("scope_parameter is required for the {$scope->value} scope.");
        }

        if ($maxAttempts < 1) {
            throw new InvalidArgumentException("max_attempts must be at least 1, got {$maxAttempts}.");
        }

        if ($windowSeconds < 1 || $windowSeconds > AttemptGuard::MAX_WINDOW_SECONDS) {
            throw new InvalidArgumentException('window_seconds must be from 1 to '.AttemptGuard::MAX_WINDOW_SECONDS.", got {$windowSeconds}.");
        }

        if ($priority < 0) {
            throw new InvalidArgumentException("priority must be at least 0, got {$priority}.");
        }

        if ($responseOverride !== null && $responseOverride !== [] && array_is_list($responseOverride)) {
            throw new InvalidArgumentException('response_override must be a JSON object.');
        }
    }

    /**
     * From the manifest's JSON: exactly FIELDS, with exact types.
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException naming the first bad field
     */
    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);
        sort($keys);
        $expected = self::FIELDS;
        sort($expected);

        if ($keys !== $expected) {
            throw new InvalidArgumentException('A rule must have exactly the fields '.implode(', ', self::FIELDS).'.');
        }

        return new self(
            id: self::string($data, 'id'),
            name: self::string($data, 'name'),
            method: self::enum($data, 'method', RequestMethod::class),
            pattern: self::string($data, 'pattern'),
            scope: self::enum($data, 'scope', Scope::class),
            scopeParameter: $data['scope_parameter'] === null ? null : self::string($data, 'scope_parameter'),
            maxAttempts: self::integer($data, 'max_attempts'),
            windowSeconds: self::integer($data, 'window_seconds'),
            priority: self::integer($data, 'priority'),
            mode: self::enum($data, 'mode', RuleMode::class),
            onFailure: self::enum($data, 'on_failure', FailurePolicy::class),
            onMissingIdentity: self::enum($data, 'on_missing_identity', MissingIdentity::class),
            responseOverride: self::responseOverride($data),
        );
    }

    /**
     * For the manifest's JSON, in FIELDS order, with enum values as strings (SPEC-006 AC-2).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'method' => $this->method->value,
            'pattern' => $this->pattern,
            'scope' => $this->scope->value,
            'scope_parameter' => $this->scopeParameter,
            'max_attempts' => $this->maxAttempts,
            'window_seconds' => $this->windowSeconds,
            'priority' => $this->priority,
            'mode' => $this->mode->value,
            'on_failure' => $this->onFailure->value,
            'on_missing_identity' => $this->onMissingIdentity->value,
            'response_override' => $this->responseOverride,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $field): string
    {
        return is_string($data[$field])
            ? $data[$field]
            : throw new InvalidArgumentException("{$field} must be a string, got ".get_debug_type($data[$field]).'.');
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function integer(array $data, string $field): int
    {
        return is_int($data[$field])
            ? $data[$field]
            : throw new InvalidArgumentException("{$field} must be an integer, got ".get_debug_type($data[$field]).'.');
    }

    /**
     * @template T of BackedEnum
     *
     * @param  array<array-key, mixed>  $data
     * @param  class-string<T>  $enum
     * @return T
     */
    private static function enum(array $data, string $field, string $enum): BackedEnum
    {
        $value = $data[$field];
        $case = is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            $allowed = implode(', ', array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases()));

            throw new InvalidArgumentException("{$field} must be one of {$allowed}, got ".(is_string($value) ? "\"{$value}\"" : get_debug_type($value)).'.');
        }

        return $case;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>|null
     */
    private static function responseOverride(array $data): ?array
    {
        $value = $data['response_override'];

        return $value === null || is_array($value)
            ? $value
            : throw new InvalidArgumentException('response_override must be a JSON object.');
    }
}
