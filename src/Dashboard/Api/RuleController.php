<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\Dashboard\Validation\Confirmation;
use Afzal\DynamicThrottle\Dashboard\Validation\RuleInput;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Support\ChangeOutcome;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Afzal\DynamicThrottle\Support\EmergencyControls;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule as ValidationRule;

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

    /**
     * The search, ignoring case on every database, with "!" as the escape: unlike a
     * backslash, it means the same in MySQL's, PostgreSQL's and SQLite's string literals.
     */
    private const string LIKE_NAME = "lower(name) like ? escape '!'";

    private const string LIKE_PATTERN = "lower(pattern) like ? escape '!'";

    /** A rule's newest exemptions it shows; api/exemptions?rule= pages through all (AC-26). */
    public const int EXEMPTIONS = 100;

    public function __construct(
        private RuleInput $input,
        private EmergencyControls $controls,
        private ValidationFactory $validation,
    ) {}

    /**
     * The rules in evaluation order, narrowed by a search of the name and the pattern,
     * the status and the mode (SPEC-022).
     */
    public function index(Request $request): JsonResponse
    {
        return ApiErrors::json(function () use ($request): JsonResponse {
            $filters = $this->validation->make($request->query(), [
                'search' => ['sometimes', 'nullable', 'string', 'max:100'],
                'enabled' => ['sometimes', 'in:true,false'],
                'mode' => ['sometimes', ValidationRule::enum(RuleMode::class)],
            ])->validate();
            $search = trim(is_string($filters['search'] ?? null) ? $filters['search'] : '');
            $like = $this->like($search);
            $enabled = $filters['enabled'] ?? null;
            $mode = $filters['mode'] ?? null;

            $page = Rule::query()
                ->when($search !== '', fn (Builder $rules) => $rules->where(
                    fn (Builder $rules) => $rules->whereRaw(self::LIKE_NAME, [$like])->orWhereRaw(self::LIKE_PATTERN, [$like]),
                ))
                ->when(is_string($enabled), fn (Builder $rules) => $rules->where('enabled', $enabled === 'true'))
                ->when(is_string($mode), fn (Builder $rules) => $rules->where('mode', $mode))
                ->orderBy('priority')->orderBy('id')
                ->paginate(self::PER_PAGE)->withQueryString()
                ->through(fn (Rule $rule): array => Payloads::rule($rule));

            return new JsonResponse($page);
        });
    }

    /**
     * A LIKE pattern that holds the search anywhere, its %, _ and ! matching themselves
     * (SPEC-022 AC-16).
     */
    private function like(string $search): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
    }

    /**
     * The Overview's counts (SPEC-021): the enabled rules by mode, and the disabled ones,
     * in one grouped query.
     */
    public function counts(): JsonResponse
    {
        $counts = ['enforcing' => 0, 'dry_run' => 0, 'disabled' => 0];
        $groups = Rule::query()->toBase()->selectRaw('enabled, mode, count(*) as rules')->groupBy('enabled', 'mode')->get();

        foreach ($groups as $group) {
            $kind = match (true) {
                ! (bool) $group->enabled => 'disabled',
                $group->mode === RuleMode::DryRun->value => 'dry_run',
                default => 'enforcing',
            };
            $counts[$kind] += is_numeric($group->rules) ? (int) $group->rules : 0;
        }

        return new JsonResponse($counts);
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
