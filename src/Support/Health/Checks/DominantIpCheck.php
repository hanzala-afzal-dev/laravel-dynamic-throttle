<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;

/**
 * One client IP that sent most of the recorded requests, over the current and the
 * previous hour (SPEC-016 AC-17): often a load balancer's or a proxy's, because
 * the trusted proxies are not set (ADR-0009). Half or more of at least 1,000
 * requests (decision 5). Only the Redis driver keeps the traffic per client.
 *
 * @internal
 */
final readonly class DominantIpCheck implements Check
{
    /** Fewer requests tell nothing. */
    private const int MIN_REQUESTS = 1_000;

    public function id(): string
    {
        return 'dominant-ip';
    }

    public function run(HealthContext $context): CheckResult
    {
        $off = $context->telemetryOff();

        if ($off !== null) {
            return CheckResult::skipped($this->id(), $off);
        }

        $hour = intdiv($context->now(), 3_600);

        try {
            $buffer = $context->buffer();
            $current = $buffer->requests($hour);

            if ($current === null) {
                return CheckResult::skipped($this->id(), 'The cache driver keeps no traffic per client: only the Redis driver checks for a dominant IP.');
            }

            $requests = $current + ($buffer->requests($hour - 1) ?? 0);
            $clients = $this->clients($buffer, $hour);
        } catch (StorageUnavailableException $exception) {
            return CheckResult::skipped($this->id(), 'The counter store cannot be read: '.HealthContext::message($exception));
        }

        $since = HealthContext::time(($hour - 1) * 3_600, 'Y-m-d H:i');

        if ($requests < self::MIN_REQUESTS) {
            return CheckResult::ok($this->id(), 'Too few requests to tell: '.number_format($requests)." since {$since}.");
        }

        arsort($clients);
        $top = (string) array_key_first($clients);
        $sent = $clients[$top] ?? 0;

        if ($sent * 2 < $requests) {
            return CheckResult::ok($this->id(), 'No client sent half of the '.number_format($requests)." requests since {$since}.");
        }

        $share = (int) round($sent * 100 / $requests);

        return CheckResult::warning($this->id(), 'One client sent '.number_format($sent).' of '.number_format($requests)." requests since {$since} ({$share}%), by the identity the ip scope gives it: {$top}. "
            .'Behind a load balancer or a proxy, that is usually its IP, because the app\'s trusted proxies are not set, so every client counts as one: set them, as Laravel\'s TrustProxies middleware does. dynamic-throttle:explain shows the hash of an IP. If the client is known, such as a partner\'s server, ignore this.');
    }

    /**
     * Each client's requests over the two hours: the top clients of each, added up.
     *
     * @return array<string, int>
     */
    private function clients(TelemetryBuffer $buffer, int $hour): array
    {
        $clients = [];

        foreach ([$hour - 1, $hour] as $each) {
            foreach ($buffer->topClients($each, TelemetryBuffer::OFFENDERS) ?? [] as $client => $sent) {
                $clients[$client] = ($clients[$client] ?? 0) + $sent;
            }
        }

        return $clients;
    }
}
