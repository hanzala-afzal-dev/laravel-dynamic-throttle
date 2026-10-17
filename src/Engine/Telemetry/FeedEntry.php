<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Telemetry;

use InvalidArgumentException;
use JsonException;

/**
 * One block or would-block in the live feed (SPEC-030), as the Redis buffer keeps it
 * for an hour. It holds the client's raw IP and user, which nothing else keeps raw
 * (ADR-0024's amendment), and never a token, a header's value, a custom scope's
 * value, the request's path or its query string (decision 3).
 *
 * @internal
 */
final readonly class FeedEntry
{
    /**
     * @param  int  $time  Unix milliseconds
     * @param  ?string  $route  the matched route's URI template, such as orders/{order}
     * @param  ?string  $ip  the client IP as the app reads it, IPv6 whole
     * @param  ?string  $user  the user the request already held, as Class:id
     * @param  string  $identity  the identity hash the rule counted, which its offenders show
     *
     * @throws InvalidArgumentException for an outcome other than a block or a would-block, or a time before 1970
     */
    public function __construct(
        public int $time,
        public string $ruleId,
        public TelemetryOutcome $outcome,
        public string $method,
        public ?string $route,
        public ?string $ip,
        public ?string $user,
        public string $identity,
    ) {
        if ($outcome !== TelemetryOutcome::Blocked && $outcome !== TelemetryOutcome::WouldBlock) {
            throw new InvalidArgumentException("The feed keeps only blocks and would-blocks, got {$outcome->value}.");
        }

        if ($time < 0) {
            throw new InvalidArgumentException("A feed entry's time is Unix milliseconds from 0, got {$time}.");
        }
    }

    /**
     * Compact JSON, on one line: a newline in a value is escaped, so a list of entries
     * can travel in one argument, a line each.
     *
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode([
            't' => $this->time,
            'r' => $this->ruleId,
            'o' => $this->outcome->value,
            'm' => $this->method,
            'p' => $this->route,
            'i' => $this->ip,
            'u' => $this->user,
            'h' => $this->identity,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * The entry toJson() wrote, or null for anything else, which a read skips.
     */
    public static function fromJson(string $json): ?self
    {
        try {
            $data = json_decode($json, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        $time = $data['t'] ?? null;
        $outcome = is_string($data['o'] ?? null) ? TelemetryOutcome::tryFrom($data['o']) : null;
        $strings = [$data['r'] ?? null, $data['m'] ?? null, $data['h'] ?? null];
        $nullable = [];

        foreach (['p', 'i', 'u'] as $key) {
            if (! array_key_exists($key, $data) || ($data[$key] !== null && ! is_string($data[$key]))) {
                return null;
            }

            $nullable[] = $data[$key];
        }

        if (! is_int($time) || $time < 0 || ! in_array($outcome, [TelemetryOutcome::Blocked, TelemetryOutcome::WouldBlock], true) || array_filter($strings, is_string(...)) !== $strings) {
            return null;
        }

        [$rule, $method, $identity] = $strings;
        [$route, $ip, $user] = $nullable;

        return new self($time, $rule, $outcome, $method, $route, $ip, $user, $identity);
    }

    /**
     * The entry as GET api/feed gives it (AC-8).
     *
     * @return array{time: int, rule: string, outcome: string, method: string, route: ?string, ip: ?string, user: ?string, identity: string}
     */
    public function toArray(): array
    {
        return [
            'time' => $this->time,
            'rule' => $this->ruleId,
            'outcome' => $this->outcome->value,
            'method' => $this->method,
            'route' => $this->route,
            'ip' => $this->ip,
            'user' => $this->user,
            'identity' => $this->identity,
        ];
    }
}
