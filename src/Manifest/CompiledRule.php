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

    /** FIELDS as sort() orders them, to check a rule whose fields come in another order. */
    private const array SORTED_FIELDS = [
        'id', 'max_attempts', 'method', 'mode', 'name', 'on_failure', 'on_missing_identity',
        'pattern', 'priority', 'response_override', 'scope', 'scope_parameter', 'window_seconds',
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
     * From the manifest's JSON: exactly FIELDS, with exact types. Every manifest
     * load runs this for every rule, so the checks are inline, and the helpers only
     * build the errors.
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException naming the first bad field
     */
    public static function fromArray(array $data): self
    {
        // The manifest writes the fields in FIELDS order, so that is compared first:
        // sorting every rule's keys cost more than all its other checks together.
        $keys = array_keys($data);

        if ($keys !== self::FIELDS) {
            sort($keys);

            if ($keys !== self::SORTED_FIELDS) {
                throw new InvalidArgumentException('A rule must have exactly the fields '.implode(', ', self::FIELDS).'.');
            }
        }

        [
            'id' => $id, 'name' => $name, 'method' => $method, 'pattern' => $pattern, 'scope' => $scope,
            'scope_parameter' => $scopeParameter, 'max_attempts' => $maxAttempts, 'window_seconds' => $windowSeconds,
            'priority' => $priority, 'mode' => $mode, 'on_failure' => $onFailure,
            'on_missing_identity' => $onMissingIdentity, 'response_override' => $responseOverride,
        ] = $data;

        return new self(
            id: is_string($id) ? $id : throw self::wrongType('id', 'a string', $id),
            name: is_string($name) ? $name : throw self::wrongType('name', 'a string', $name),
            method: (is_string($method) ? RequestMethod::tryFrom($method) : null) ?? throw self::unknown('method', RequestMethod::class, $method),
            pattern: is_string($pattern) ? $pattern : throw self::wrongType('pattern', 'a string', $pattern),
            scope: (is_string($scope) ? Scope::tryFrom($scope) : null) ?? throw self::unknown('scope', Scope::class, $scope),
            scopeParameter: $scopeParameter === null || is_string($scopeParameter) ? $scopeParameter : throw self::wrongType('scope_parameter', 'a string', $scopeParameter),
            maxAttempts: is_int($maxAttempts) ? $maxAttempts : throw self::wrongType('max_attempts', 'an integer', $maxAttempts),
            windowSeconds: is_int($windowSeconds) ? $windowSeconds : throw self::wrongType('window_seconds', 'an integer', $windowSeconds),
            priority: is_int($priority) ? $priority : throw self::wrongType('priority', 'an integer', $priority),
            mode: (is_string($mode) ? RuleMode::tryFrom($mode) : null) ?? throw self::unknown('mode', RuleMode::class, $mode),
            onFailure: (is_string($onFailure) ? FailurePolicy::tryFrom($onFailure) : null) ?? throw self::unknown('on_failure', FailurePolicy::class, $onFailure),
            onMissingIdentity: (is_string($onMissingIdentity) ? MissingIdentity::tryFrom($onMissingIdentity) : null) ?? throw self::unknown('on_missing_identity', MissingIdentity::class, $onMissingIdentity),
            responseOverride: $responseOverride === null || is_array($responseOverride) ? $responseOverride : throw new InvalidArgumentException('response_override must be a JSON object.'),
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

    private static function wrongType(string $field, string $type, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException("{$field} must be {$type}, got ".get_debug_type($value).'.');
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    private static function unknown(string $field, string $enum, mixed $value): InvalidArgumentException
    {
        $allowed = implode(', ', array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases()));

        return new InvalidArgumentException("{$field} must be one of {$allowed}, got ".(is_string($value) ? "\"{$value}\"" : get_debug_type($value)).'.');
    }
}
