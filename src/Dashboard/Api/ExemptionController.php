<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\Dashboard\Validation\Confirmation;
use Afzal\DynamicThrottle\Dashboard\Validation\ExemptionInput;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Support\ChangeOutcome;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Afzal\DynamicThrottle\Support\EmergencyControls;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The API's exemptions (SPEC-017): listed newest first, 100 a page, all or one
 * rule's, and created and deleted with one audit entry each. An exemption is not
 * edited: delete it and create another.
 *
 * @internal
 */
final readonly class ExemptionController
{
    /** Exemptions a page (decision 9). */
    public const int PER_PAGE = 100;

    public function __construct(
        private ExemptionInput $input,
        private EmergencyControls $controls,
        private ValidationFactory $validation,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiErrors::json(function () use ($request): JsonResponse {
            $filter = $this->validation->make($request->query(), ['rule' => ['sometimes', 'integer', 'min:1']])->validate();
            $query = Exemption::query()->orderByDesc('id');

            if (isset($filter['rule'])) {
                $query->where('rule_id', (int) $filter['rule']);
            }

            return new JsonResponse($query->paginate(self::PER_PAGE)->through(fn (Exemption $exemption): array => Payloads::exemption($exemption)));
        });
    }

    public function store(Request $request): JsonResponse
    {
        return ApiErrors::json(function () use ($request): JsonResponse {
            $input = $request->all();
            $attributes = $this->input->validate($input);
            Confirmation::exemption($attributes, $input);

            return $this->changed($this->controls->createExemption($attributes, Actor::of($request)), null, 201);
        });
    }

    public function destroy(Request $request, string $exemption): JsonResponse
    {
        $found = Exemption::query()->findOrFail((int) $exemption);

        return ApiErrors::json(fn (): JsonResponse => $this->changed($this->controls->deleteExemption($found, Actor::of($request)), $found));
    }

    /**
     * The exemption as the change last read it, falling back to the one the request found.
     */
    private function changed(ChangeOutcome $outcome, ?Exemption $found, int $status = 200): JsonResponse
    {
        $exemption = $outcome->subject instanceof Exemption ? $outcome->subject : $found;

        return Changed::response(['exemption' => $exemption instanceof Exemption ? Payloads::exemption($exemption) : null], $outcome, $status);
    }
}
