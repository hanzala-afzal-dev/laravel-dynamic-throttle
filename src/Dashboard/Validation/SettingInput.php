<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Validation;

use Afzal\DynamicThrottle\Console\Duration;
use Afzal\DynamicThrottle\Support\ScaleFactor;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * What the dashboard and its API accept for bypass and the scale factor (SPEC-018):
 * what the CLI accepts, through the same parsers (SPEC-013). A setting turned on ends
 * after "for", an hour by default, or never with "forever".
 *
 * @internal
 */
final readonly class SettingInput
{
    public function __construct(
        private ValidationFactory $validation,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{on: bool, until: ?CarbonInterface}
     *
     * @throws ValidationException
     */
    public function bypass(array $input): array
    {
        $data = array_intersect_key($input, array_flip(['state', 'for', 'forever']));
        $validated = $this->validated($data, ['state' => ['required', 'in:on,off']], ($data['state'] ?? null) === 'off');
        $on = $validated['state'] === 'on';

        return ['on' => $on, 'until' => $on ? $this->until($validated) : null];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{percent: ?int, until: ?CarbonInterface}
     *
     * @throws ValidationException
     */
    public function scale(array $input): array
    {
        $data = array_intersect_key($input, array_flip(['factor', 'for', 'forever']));
        $validated = $this->validated($data, ['factor' => ['required', 'string', $this->factor(...)]], ($data['factor'] ?? null) === 'off');
        $factor = $validated['factor'];

        return $factor === 'off' || ! is_string($factor)
            ? ['percent' => null, 'until' => null]
            : ['percent' => ScaleFactor::percent($factor), 'until' => $this->until($validated)];
    }

    /**
     * The setting's own rules, with "for" and "forever": not both, and neither to turn
     * a setting off.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(array $data, array $rules, bool $off): array
    {
        $validator = $this->validation->make($data, [
            ...$rules,
            'for' => ['sometimes', 'string', $this->duration(...)],
            'forever' => ['sometimes', 'boolean'],
        ]);

        $validator->after(function (Validator $validator) use ($data, $off): void {
            $forever = filter_var($data['forever'] ?? false, FILTER_VALIDATE_BOOL);

            if ($off && (isset($data['for']) || $forever)) {
                $validator->errors()->add('for', 'for and forever do not apply to off.');
            } elseif (isset($data['for']) && $forever) {
                $validator->errors()->add('for', 'Pass for or forever, not both.');
            }
        });

        return $validator->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function until(array $validated): ?CarbonInterface
    {
        if (filter_var($validated['forever'] ?? false, FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $for = $validated['for'] ?? null;

        return Date::now()->addSeconds(Duration::seconds(is_string($for) ? $for : '1h'));
    }

    /**
     * @param  Closure(string): mixed  $fail
     */
    private function duration(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            Duration::seconds(is_string($value) ? $value : '');
        } catch (InvalidArgumentException) {
            $fail('for must be a whole number and a unit, s, m, h or d, such as 30m, from 1 second to 365 days.');
        }
    }

    /**
     * @param  Closure(string): mixed  $fail
     */
    private function factor(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === 'off') {
            return;
        }

        try {
            ScaleFactor::percent(is_string($value) ? $value : '');
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }
}
