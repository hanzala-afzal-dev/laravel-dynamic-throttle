<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\Dashboard\Validation\Confirmation;
use Afzal\DynamicThrottle\Dashboard\Validation\RuleInput;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Support\ChangeOutcome;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Afzal\DynamicThrottle\Support\EmergencyControls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The API's rules (SPEC-017): listed in evaluation order, 100 a page, shown with
 * the exemptions that name them, and created, changed and deleted with one audit
 * entry each, in the change's own transaction (decision 4).
 *
 * @internal
 */
final readonly class RuleController
{
    /** Rules a page (decision 9). */
    public const int PER_PAGE = 100;

    /** A rule's newest exemptions it shows; api/exemptions?rule= pages through all (AC-26). */
    public const int EXEMPTIONS = 100;

    public function __construct(
        private RuleInput $input,
        private EmergencyControls $controls,
    ) {}

    public function index(): JsonResponse
    {
        $page = Rule::query()->orderBy('priority')->orderBy('id')->paginate(self::PER_PAGE)
            ->through(fn (Rule $rule): array => Payloads::rule($rule));

        return new JsonResponse($page);
    }

    public function show(string $rule): JsonResponse
    {
        $found = Rule::query()->findOrFail((int) $rule);
        $exemptions = Exemption::query()->where('rule_id', $found->id)->orderByDesc('id')->limit(self::EXEMPTIONS)->get();

        return new JsonResponse([
            'rule' => Payloads::rule($found),
            'exemptions' => $exemptions->map(fn (Exemption $exemption): array => Payloads::exemption($exemption))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return ApiErrors::json(function () use ($request): JsonResponse {
            $outcome = $this->controls->createRule($this->input->validate($request->all()), Actor::of($request));

            return $this->changed($outcome, null, 201);
        });
    }

    public function update(Request $request, string $rule): JsonResponse
    {
        $found = Rule::query()->findOrFail((int) $rule);

        return ApiErrors::json(function () use ($request, $found): JsonResponse {
            $input = $request->all();
            $outcome = $this->controls->updateRule($found, function (Rule $current) use ($input): array {
                $attributes = $this->input->validate($input, $current);
                Confirmation::ruleChanged($current, $attributes, $input);

                return $attributes;
            }, Actor::of($request));

            return $this->changed($outcome, $found);
        });
    }

    public function destroy(Request $request, string $rule): JsonResponse
    {
        $found = Rule::query()->findOrFail((int) $rule);

        return ApiErrors::json(function () use ($request, $found): JsonResponse {
            $input = $request->all();
            $outcome = $this->controls->deleteRule($found, function (Rule $current) use ($input): void {
                Confirmation::ruleDeleted($current, $input);
            }, Actor::of($request));

            return $this->changed($outcome, $found);
        });
    }

    /**
     * The rule as the change last read it, falling back to the rule as the request found it.
     */
    private function changed(ChangeOutcome $outcome, ?Rule $found, int $status = 200): JsonResponse
    {
        $rule = $outcome->subject instanceof Rule ? $outcome->subject : $found;

        return Changed::response(['rule' => $rule instanceof Rule ? Payloads::rule($rule) : null], $outcome, $status);
    }
}
