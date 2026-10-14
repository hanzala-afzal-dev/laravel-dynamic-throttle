<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Support\Explainer;
use Afzal\DynamicThrottle\Support\Explanation\ExplanationFailed;
use Afzal\DynamicThrottle\Support\Explanation\ExplanationRequest;
use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * POST api/explain (SPEC-017): what dynamic-throttle:explain --json prints, for a
 * request described in the body, so a bearer token never lands in a URL or an
 * access log (decision 2). Bearer tokens and header values are masked to their
 * last 4 characters, and values under 12 characters hidden whole; their hashes
 * stay whole, so they still tie to counters and events (decision 3).
 *
 * @internal
 */
final readonly class ExplainController
{
    /** The most headers a request to explain carries. */
    private const int MAX_HEADERS = 20;

    /** The identities of api_token and header rules, whose values are secrets. */
    private const array SECRET_PREFIXES = ['token:', 'header:'];

    /** The shortest value that keeps its last 4 characters. */
    private const int SHORTEST_SHOWN = 12;

    public function __construct(
        private Explainer $explainer,
        private ValidationFactory $validation,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return ApiErrors::json(function () use ($request): JsonResponse {
            $input = $this->validated($request->all());

            try {
                $explanation = $this->explainer->explain(ExplanationRequest::of(
                    self::text($input['method'] ?? null),
                    self::text($input['path'] ?? null),
                    self::text($input['ip'] ?? null) ?: '127.0.0.1',
                    self::text($input['user'] ?? null) ?: null,
                    self::text($input['guard'] ?? null) ?: null,
                    array_map(self::text(...), is_array($input['headers'] ?? null) ? array_values($input['headers']) : []),
                ));
            } catch (InvalidArgumentException|ExplanationFailed $exception) {
                throw ValidationException::withMessages(['request' => $exception->getMessage()]);
            }

            return new JsonResponse(self::masked($explanation->jsonSerialize()), 200, [], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(array $input): array
    {
        $validator = $this->validation->make($input, [
            'method' => ['required', 'string', $this->method(...)],
            'path' => ['required', 'string', 'max:2048'],
            'ip' => ['nullable', 'ip'],
            'user' => ['nullable', 'string', 'max:255'],
            'guard' => ['nullable', 'string', 'max:64'],
            'headers' => ['nullable', 'array', 'list', 'max:'.self::MAX_HEADERS],
            'headers.*' => ['string', 'max:8192', 'regex:/\A[A-Za-z0-9-]+:/'],
        ], ['headers.*.regex' => 'Each header is "Name: value".']);

        $validator->after(function (Validator $validator) use ($input): void {
            if (($input['guard'] ?? null) !== null && ($input['user'] ?? null) === null) {
                $validator->errors()->add('guard', 'guard needs user: it names the guard that looks the user up.');
            }
        });

        return $validator->validate();
    }

    /**
     * @param  Closure(string): mixed  $fail
     */
    private function method(string $attribute, mixed $value, Closure $fail): void
    {
        $method = is_string($value) ? RequestMethod::tryFrom(strtoupper($value)) : null;

        if ($method === null || $method === RequestMethod::Any) {
            $fail('The method must be GET, HEAD, POST, PUT, PATCH, DELETE or OPTIONS.');
        }
    }

    /**
     * The explanation with each secret identity masked.
     *
     * @param  array<string, mixed>  $explanation
     * @return array<string, mixed>
     */
    private static function masked(array $explanation): array
    {
        $rules = is_array($explanation['rules'] ?? null) ? $explanation['rules'] : [];

        foreach ($rules as $index => $rule) {
            if (is_array($rule) && is_string($rule['identity'] ?? null)) {
                $rules[$index]['identity'] = self::mask($rule['identity']);
            }
        }

        return [...$explanation, 'rules' => $rules];
    }

    private static function mask(string $identity): string
    {
        foreach (self::SECRET_PREFIXES as $prefix) {
            if (str_starts_with($identity, $prefix)) {
                $value = substr($identity, strlen($prefix));

                return $prefix.'…'.(mb_strlen($value) >= self::SHORTEST_SHOWN ? mb_substr($value, -4) : '');
            }
        }

        return $identity;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
