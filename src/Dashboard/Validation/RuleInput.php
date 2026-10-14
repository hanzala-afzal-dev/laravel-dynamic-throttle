<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Validation;

use Afzal\DynamicThrottle\DynamicThrottle;
use Afzal\DynamicThrottle\Engine\Internal\AttemptGuard;
use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Models\Rule;
use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * What the dashboard and its API accept for a rule (SPEC-018): at least as strict as
 * the compiler, so a rule saved through them always compiles, and stricter where a
 * mistake would only show at request time, such as a custom scope the app never
 * registered (decision 6).
 *
 * @internal
 */
final readonly class RuleInput
{
    /** The most enabled rules the dashboard allows (ADR-0015, ADR-0007). */
    public const int MAX_ENABLED = 500;

    /** The largest number an unsigned integer column holds. */
    private const int MAX_UNSIGNED = 4_294_967_295;

    /** response_override's most bytes, once encoded as the model stores it. */
    private const int MAX_OVERRIDE_BYTES = 4_096;

    /** The fields a rule takes from input. */
    private const array FIELDS = [
        'name', 'description', 'method', 'pattern', 'scope', 'scope_parameter', 'max_attempts',
        'window_seconds', 'priority', 'mode', 'on_failure', 'on_missing_identity', 'response_override', 'enabled',
    ];

    /** No control character, such as a terminal escape, and no invisible format character, such as a right-to-left override. */
    private const string NO_CONTROL = '/\A[^\p{Cc}\p{Cf}]*\z/u';

    /** As NO_CONTROL, but with line breaks and tabs. */
    private const string TEXT = '/\A[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F\p{Cf}]*\z/u';

    /** The most bytes of a pattern, which the compiler counts (CompiledRule). */
    private const int MAX_PATTERN_BYTES = 255;

    public function __construct(
        private ValidationFactory $validation,
        private DynamicThrottle $throttle,
    ) {}

    /**
     * The attributes of a new rule, or of $rule changed by the input: a change is
     * checked against the whole rule it gives.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> ready for Rule::create() or fill()
     *
     * @throws ValidationException
     */
    public function validate(array $input, ?Rule $rule = null): array
    {
        // An empty string is no value, as Laravel's ConvertEmptyStringsToNull makes it, which an app may remove.
        $given = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, array_intersect_key($input, array_flip(self::FIELDS)));
        $data = [...$this->current($rule), ...$given];
        $validator = $this->validation->make($data, $this->rules($data['scope'] ?? null));

        $validator->after(function (Validator $validator) use ($data, $rule): void {
            $enabling = filter_var($data['enabled'] ?? true, FILTER_VALIDATE_BOOL) && $rule?->enabled !== true;

            if ($enabling && Rule::query()->where('enabled', true)->count() >= self::MAX_ENABLED) {
                $validator->errors()->add('enabled', self::MAX_ENABLED.' rules are enabled, the most the dashboard allows.');
            }
        });

        return $validator->validate();
    }

    /**
     * A rule's fields as input gives them: enums as their values.
     *
     * @return array<string, mixed>
     */
    private function current(?Rule $rule): array
    {
        return $rule instanceof Rule ? array_intersect_key($rule->attributesToArray(), array_flip(self::FIELDS)) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(mixed $scope): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'regex:'.self::NO_CONTROL],
            'description' => ['nullable', 'string', 'max:1000', 'regex:'.self::TEXT],
            'method' => ['filled', ValidationRule::enum(RequestMethod::class)],
            'pattern' => ['required', 'string', 'max:255', 'regex:/\A[^\s\p{Cc}\p{Cf}]+\z/u', $this->patternBytes(...), $this->noTrailingSlash(...)],
            'scope' => ['required', ValidationRule::enum(Scope::class)],
            'scope_parameter' => $this->scopeParameter($scope),
            'max_attempts' => ['required', 'integer', 'between:1,'.self::MAX_UNSIGNED],
            'window_seconds' => ['required', 'integer', 'between:1,'.AttemptGuard::MAX_WINDOW_SECONDS],
            'priority' => ['filled', 'integer', 'between:0,'.self::MAX_UNSIGNED],
            'mode' => ['filled', ValidationRule::enum(RuleMode::class)],
            'on_failure' => ['filled', ValidationRule::enum(FailurePolicy::class)],
            'on_missing_identity' => ['filled', ValidationRule::enum(MissingIdentity::class)],
            'response_override' => ['nullable', 'array', $this->responseOverride(...)],
            'enabled' => ['filled', 'boolean'],
        ];
    }

    /**
     * A header name for the header scope, a registered scope for the custom one, and
     * nothing for the others.
     *
     * @return list<mixed>
     */
    private function scopeParameter(mixed $scope): array
    {
        return match ($scope) {
            Scope::Header->value => ['required', 'string', 'regex:/\A[A-Za-z0-9-]{1,64}\z/'],
            Scope::Custom->value => ['required', 'string', 'max:64', $this->registeredScope(...)],
            default => ['prohibited'],
        };
    }

    /**
     * @param  Closure(string): mixed  $fail
     */
    private function registeredScope(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $this->throttle->scopeResolver($value) === null) {
            $fail("No custom scope \"{$value}\" is registered: register it with DynamicThrottle::scope().");
        }
    }

    /**
     * The compiler counts a pattern's bytes, and a character outside ASCII takes more than one.
     *
     * @param  Closure(string): mixed  $fail
     */
    private function patternBytes(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > self::MAX_PATTERN_BYTES) {
            $fail('The pattern must be at most '.self::MAX_PATTERN_BYTES.' bytes, got '.strlen($value).'.');
        }
    }

    /**
     * Request paths never end with a slash, so a pattern that does never matches
     * (SPEC-007). The root is "/".
     *
     * @param  Closure(string): mixed  $fail
     */
    private function noTrailingSlash(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $value !== '/' && str_ends_with($value, '/')) {
            $fail('The pattern must not end with a slash: request paths never do, so it would never match. Use / for the root.');
        }
    }

    /**
     * A JSON object, of at most 4 KB once encoded. The compiler takes an empty one.
     *
     * @param  Closure(string): mixed  $fail
     */
    private function responseOverride(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        if ($value !== [] && array_is_list($value)) {
            $fail('The response override must be a JSON object.');

            return;
        }

        $json = json_encode($value);

        if ($json === false || strlen($json) > self::MAX_OVERRIDE_BYTES) {
            $fail('The response override must be valid JSON of at most '.self::MAX_OVERRIDE_BYTES.' bytes.');
        }
    }
}
