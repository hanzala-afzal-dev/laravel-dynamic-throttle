<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\Dashboard\Validation\Confirmation;
use Afzal\DynamicThrottle\Dashboard\Validation\SettingInput;
use Afzal\DynamicThrottle\Enums\SettingName;
use Afzal\DynamicThrottle\Models\Setting;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Afzal\DynamicThrottle\Support\EmergencyControls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * GET api/settings, and PUT api/settings/bypass and api/settings/scale (SPEC-017):
 * the emergency controls, changed through the CLI's own code, so both write the
 * same audit entries (decision 6).
 *
 * @internal
 */
final readonly class SettingController
{
    public function __construct(
        private SettingInput $input,
        private EmergencyControls $controls,
    ) {}

    public function show(): JsonResponse
    {
        return new JsonResponse($this->state());
    }

    public function bypass(Request $request): JsonResponse
    {
        return ApiErrors::json(function () use ($request): JsonResponse {
            $input = $request->all();
            ['on' => $on, 'until' => $until] = $this->input->bypass($input);
            Confirmation::bypass($on, $until, $input);
            $actor = Actor::of($request);

            $outcome = $on ? $this->controls->bypass($until, $actor) : $this->controls->endBypass($actor);

            return Changed::response(['settings' => $this->state()], $outcome);
        });
    }

    public function scale(Request $request): JsonResponse
    {
        return ApiErrors::json(function () use ($request): JsonResponse {
            $input = $request->all();
            ['percent' => $percent, 'until' => $until] = $this->input->scale($input);
            Confirmation::scale($percent, $until, $input);
            $actor = Actor::of($request);

            $outcome = $percent === null ? $this->controls->endScale($actor) : $this->controls->scale($percent, $until, $actor);

            return Changed::response(['settings' => $this->state()], $outcome);
        });
    }

    /**
     * Bypass and the scale factor, each off once it has ended.
     *
     * @return array{bypass: array{on: bool, until: ?string}, scale: array{factor: ?string, until: ?string}}
     */
    private function state(): array
    {
        $bypass = $this->current(SettingName::BypassAll);
        $scale = $this->current(SettingName::ScaleFactor);

        return [
            'bypass' => ['on' => $bypass instanceof Setting, 'until' => $this->until($bypass)],
            'scale' => ['factor' => $scale?->value, 'until' => $this->until($scale)],
        ];
    }

    private function current(SettingName $name): ?Setting
    {
        $setting = Setting::query()->where('name', $name->value)->first();

        return $setting instanceof Setting && ($setting->expires_at === null || $setting->expires_at->greaterThan(Date::now()))
            ? $setting
            : null;
    }

    private function until(?Setting $setting): ?string
    {
        return $setting?->expires_at?->clone()->setTimezone('UTC')->toIso8601String();
    }
}
