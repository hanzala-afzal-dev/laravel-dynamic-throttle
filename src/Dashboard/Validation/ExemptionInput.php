<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Validation;

use Afzal\DynamicThrottle\DynamicThrottle;
use Afzal\DynamicThrottle\Engine\Exemptions\IpRange;
use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Support\DefaultGuard;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * What the dashboard and its API accept for an exemption (SPEC-018), so an exemption
 * saved through them always compiles (decision 6). A user is named as the user scope
 * matches it, Class:id, or by its id alone, as a user of the default guard's model.
 *
 * @internal
 */
final readonly class ExemptionInput
{
    /** The fields an exemption takes from input. */
    private const array FIELDS = ['subject', 'subject_parameter', 'value', 'rule_id', 'expires_at', 'reason'];

    /** No control character, such as a terminal escape, and no invisible format character, such as a right-to-left override. */
    private const string NO_CONTROL = '/\A[^\p{Cc}\p{Cf}]*\z/u';

    public function __construct(
        private ValidationFactory $validation,
        private DynamicThrottle $throttle,
        private Config $config,
        private DefaultGuard $defaultGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> ready for Exemption::create()
     *
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        // An empty string is no value, as Laravel's ConvertEmptyStringsToNull makes it.
        $data = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, array_intersect_key($input, array_flip(self::FIELDS)));
        $subject = $data['subject'] ?? null;

        if ($subject === ExemptionSubject::User->value && (is_string($data['value'] ?? null) || is_int($data['value'] ?? null))) {
            $data['value'] = $this->user((string) $data['value']);
        }

        $validated = $this->validation->make($data, [
            'subject' => ['required', ValidationRule::enum(ExemptionSubject::class)],
            'subject_parameter' => $subject === ExemptionSubject::Custom->value
                ? ['required', 'string', 'max:64', $this->registeredScope(...)]
                : ['prohibited'],
            'value' => match ($subject) {
                ExemptionSubject::Ip->value => ['required', 'string', $this->ipRange(...)],
                ExemptionSubject::User->value => ['required', 'string', 'max:255', 'regex:'.self::NO_CONTROL, 'regex:/\A[^:]+:.+\z/s'],
                default => ['required', 'string', 'max:255', 'regex:'.self::NO_CONTROL],
            },
            'rule_id' => ['nullable', $this->ruleExists(...)],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:255', 'regex:'.self::NO_CONTROL],
        ])->validate();

        if (isset($validated['rule_id'])) {
            $validated['rule_id'] = $this->ruleId($validated['rule_id']);
        }

        return $validated;
    }

    /**
     * A rule's id as JSON sends it, an integer, or as a form does, its digits; null for anything else.
     */
    private function ruleId(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/\A[1-9]\d{0,17}\z/', $value) === 1 => (int) $value,
            default => null,
        };
    }

    /**
     * A user as the user scope matches it: Class:id as given, or an id alone as a user of
     * the default guard's model, which stays an id, and so refused, without one.
     */
    private function user(string $value): string
    {
        if (str_contains($value, ':')) {
            return $value;
        }

        // The app's own default guard: behind auth:<guard>, the request's default is the admin's (SPEC-017 AC-25).
        $guard = $this->defaultGuard->name;
        $provider = is_string($guard) ? $this->config->get("auth.guards.{$guard}.provider") : null;
        $model = is_string($provider) ? $this->config->get("auth.providers.{$provider}.model") : null;

        return is_string($model) && $model !== '' ? "{$model}:{$value}" : $value;
    }

    /**
     * @param  Closure(string): mixed  $fail
     */
    private function ipRange(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            IpRange::parse(is_string($value) ? $value : '');
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }

    /**
     * @param  Closure(string): mixed  $fail
     */
    private function ruleExists(string $attribute, mixed $value, Closure $fail): void
    {
        $id = $this->ruleId($value);

        if ($id === null) {
            $fail('The rule must be given by its id.');
        } elseif (! Rule::query()->whereKey($id)->exists()) {
            $fail("Rule {$id} does not exist.");
        }
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
}
