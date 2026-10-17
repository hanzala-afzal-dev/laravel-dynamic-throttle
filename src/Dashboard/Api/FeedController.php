<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Telemetry\FeedEntry;
use Afzal\DynamicThrottle\Models\Rule;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET api/feed (SPEC-030): the last hour's newest blocks and would-blocks, newest
 * first, with their rules' names, and with `since` only the newer ones. The feed is
 * null on a store that keeps none, and empty with telemetry or the live feed off.
 *
 * @internal
 */
final readonly class FeedController
{
    public function __construct(
        private TelemetryBuffer $buffer,
        private Clock $clock,
        private ValidationFactory $validation,
        private Config $config,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return ApiErrors::json(function () use ($request): JsonResponse {
            $since = $this->validation->make($request->query(), ['since' => ['sometimes', 'integer', 'min:0']])->validate()['since'] ?? null;

            if ($this->config->get('dynamic-throttle.telemetry.enabled') === false || $this->config->get('dynamic-throttle.telemetry.live_feed') === false) {
                return new JsonResponse(['feed' => []]);
            }

            $entries = $this->buffer->feed(intdiv($this->clock->microseconds(), 60_000_000), is_numeric($since) ? (int) $since : null);

            if ($entries === null) {
                return new JsonResponse(['feed' => null]);
            }

            $ids = array_values(array_unique(array_map(fn (FeedEntry $entry): int => (int) $entry->ruleId, $entries)));
            $names = $ids === [] ? [] : Rule::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

            return new JsonResponse(['feed' => array_map(fn (FeedEntry $entry): array => [
                ...$entry->toArray(),
                'rule' => (int) $entry->ruleId,
                'rule_name' => $names[(int) $entry->ruleId] ?? null,
            ], $entries)]);
        });
    }
}
