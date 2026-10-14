<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Models\Rule;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * GET api/rules/{id}/offenders (SPEC-017): the clients a rule blocked or would have
 * blocked most in the current or the previous hour, by identity hash, as the
 * counter store keeps them (decision 11), and how many different clients. Both are
 * null where the driver keeps neither.
 *
 * @internal
 */
final readonly class OffendersController
{
    public function __construct(
        private TelemetryBuffer $buffer,
        private Clock $clock,
        private ValidationFactory $validation,
    ) {}

    public function __invoke(Request $request, string $rule): JsonResponse
    {
        $found = Rule::query()->findOrFail((int) $rule);

        return ApiErrors::json(function () use ($request, $found): JsonResponse {
            $which = $this->validation->make($request->query(), ['hour' => ['sometimes', 'string', 'in:current,previous']])->validate()['hour'] ?? 'current';
            $hour = intdiv(intdiv($this->clock->microseconds(), 1_000_000), 3_600) - ($which === 'previous' ? 1 : 0);
            $offenders = $this->buffer->offenders((string) $found->id, $hour, TelemetryBuffer::OFFENDERS);

            return new JsonResponse([
                'hour' => Payloads::time(Date::createFromTimestamp($hour * 3_600, 'UTC')),
                'offenders' => $offenders === null ? null : array_map(
                    fn (int|string $hash, int $count): array => ['hash' => (string) $hash, 'count' => $count],
                    array_keys($offenders),
                    array_values($offenders),
                ),
                'clients' => $this->buffer->clients((string) $found->id, $hour),
            ]);
        });
    }
}
