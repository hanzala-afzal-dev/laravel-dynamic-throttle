<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Explanation;

use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Manifest\ManifestSettings;
use Afzal\DynamicThrottle\Support\ScaleFactor;
use Illuminate\Support\Facades\Date;
use JsonSerializable;

/**
 * What dynamic-throttle:explain shows (SPEC-014), as text or as JSON.
 *
 * @internal
 */
final readonly class Explanation implements JsonSerializable
{
    /**
     * @param  string  $path  as the rules match it
     * @param  ?string  $guard  the guard the user was looked up with
     * @param  ?Manifest  $manifest  null when it cannot be read, which only a request the steps before it let through allows
     * @param  ?string  $manifestError  why it cannot be read
     * @param  ManifestSettings  $settings  those that apply now
     * @param  bool  $global  dynamic.throttle is global middleware
     * @param  list<ExplainedRule>  $rules  in evaluation order
     * @param  bool  $countersRead  every counter the rules count on was read
     * @param  ?string  $countersError  why the counters were not read
     * @param  int  $maxRulesPerRequest  the cap, for the rules over it (ADR-0025)
     */
    public function __construct(
        public ExplanationRequest $request,
        public string $path,
        public ?string $guard,
        public ?Manifest $manifest,
        public ?string $manifestError,
        public ManifestSettings $settings,
        public ?ExplainedRoute $route,
        public bool $global,
        public array $rules,
        public bool $countersRead,
        public ?string $countersError,
        public ExplainedDecision $decision,
        public int $maxRulesPerRequest,
    ) {}

    /**
     * The JSON object, with times in ISO 8601 in UTC (decision 6).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'request' => [
                'method' => $this->request->method,
                'path' => $this->path,
                'ip' => $this->request->ip,
                'user' => $this->request->user,
                'guard' => $this->guard,
                'headers' => $this->request->headerNames(),
            ],
            'manifest' => $this->manifest instanceof Manifest ? [
                'version' => $this->manifest->version,
                'rules' => count($this->manifest->rules()),
                'exemptions' => count($this->manifest->exemptions->all()),
            ] : null,
            'settings' => [
                'bypass' => $this->settings->bypass ? ['until' => $this->time($this->settings->bypassUntil)] : null,
                'scale' => $this->settings->scale !== null ? ['factor' => ScaleFactor::format($this->settings->scale), 'until' => $this->time($this->settings->scaleUntil)] : null,
            ],
            'route' => $this->route?->jsonSerialize(),
            'rules' => array_map(fn (ExplainedRule $rule): array => $rule->jsonSerialize(), $this->rules),
            'counters' => ['read' => $this->countersRead, 'error' => $this->countersError],
            'decision' => $this->decision->jsonSerialize(),
        ];
    }

    private function time(?int $timestamp): ?string
    {
        return $timestamp === null ? null : Date::createFromTimestamp($timestamp)->toIso8601ZuluString();
    }
}
