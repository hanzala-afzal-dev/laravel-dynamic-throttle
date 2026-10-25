<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Support\Health\Checks\ApcuCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\AppKeyCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\AttachedCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\CacheClearCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\ConfigCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\DashboardCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\DominantIpCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\EmergencyCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\ExceptCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\GuestsCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\IgnoredCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\ManifestCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\MetricsCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\MiddlewareOrderCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\RedisTimeoutsCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\StoreCheck;
use Afzal\DynamicThrottle\Support\Health\Checks\TwoLimitersCheck;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Runs every health check, in order, for dynamic-throttle:health and the JSON
 * API's endpoint (SPEC-016, SPEC-017). A check that throws is that check's
 * error, and the others still run (AC-3). Nothing here writes.
 *
 * @internal
 */
final readonly class HealthChecks
{
    public function __construct(
        private Container $container,
    ) {}

    public function run(): HealthReport
    {
        $context = new HealthContext($this->container, $this->container->make('config'), $this->container->make(Clock::class));
        $results = [];

        foreach ($this->checks() as $check) {
            try {
                $results[] = $check->run($context);
            } catch (Throwable $exception) {
                $results[] = CheckResult::error($check->id(), HealthContext::message($exception));
            }
        }

        return new HealthReport($results);
    }

    /**
     * The checks, in the order the report lists them: the config and the storage, the
     * routes, the manifest and what it applies, the counter store's state, and what the
     * rules saw.
     *
     * @return list<Check>
     */
    private function checks(): array
    {
        return [
            new ConfigCheck,
            new StoreCheck,
            new CacheClearCheck,
            new RedisTimeoutsCheck,
            new AppKeyCheck,
            new ApcuCheck,
            new DashboardCheck,
            new AttachedCheck,
            new ExceptCheck,
            new TwoLimitersCheck,
            new MiddlewareOrderCheck,
            new ManifestCheck,
            new EmergencyCheck,
            new MetricsCheck,
            new GuestsCheck,
            new DominantIpCheck,
            new IgnoredCheck,
        ];
    }
}
