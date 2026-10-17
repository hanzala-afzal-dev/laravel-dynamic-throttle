<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\Dashboard\Validation\Confirmation;
use Afzal\DynamicThrottle\Dashboard\Validation\ExemptionInput;
use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Support\ChangeOutcome;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Afzal\DynamicThrottle\Support\EmergencyControls;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule as ValidationRule;

/**
 * The API's exemptions (SPEC-017): listed newest first, 100 a page, filtered by
 * state, subject and rule (SPEC-023), and created and deleted with one audit
 * entry each. An exemption is not edited: delete it and create another.
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
            $filters = $this->validation->make($request->query(), [
                'state' => ['sometimes', 'string', 'in:active,expired'],
                'subject' => ['sometimes', ValidationRule::enum(ExemptionSubject::class)],
                'rule' => ['sometimes', 'regex:/^(none|[1-9][0-9]{0,17})$/'],
            ], ['rule.regex' => 'The rule filter must be a rule\'s id or none.'])->validate();
            $state = $filters['state'] ?? null;
            $subject = $filters['subject'] ?? null;
            $rule = $filters['rule'] ?? null;
            $now = Date::now();

            $page = Exemption::query()
                ->when($state === 'active', fn (Builder $exemptions) => $exemptions->where(
                    fn (Builder $exemptions) => $exemptions->whereNull('expires_at')->orWhere('expires_at', '>', $now),
                ))
                ->when($state === 'expired', fn (Builder $exemptions) => $exemptions->where('expires_at', '<=', $now))
                ->when(is_string($subject), fn (Builder $exemptions) => $exemptions->where('subject', $subject))
                ->when($rule === 'none', fn (Builder $exemptions) => $exemptions->whereNull('rule_id'))
                ->when(is_string($rule) && $rule !== 'none', fn (Builder $exemptions) => $exemptions->where('rule_id', (int) $rule))
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE)->withQueryString()
                ->through(fn (Exemption $exemption): array => Payloads::exemption($exemption));

            return new JsonResponse($page);
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
