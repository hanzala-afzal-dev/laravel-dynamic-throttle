<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests;

use Afzal\DynamicThrottle\DynamicThrottleServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * composer test turns APCu off in the CLI. Where php.ini turns it on, as for other
     * runs, each test still starts without the failures an earlier one left there
     * (SPEC-012). A CLI process has APCu to itself, so this is safe under --parallel.
     */
    protected function setUp(): void
    {
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            apcu_clear_cache();
        }

        parent::setUp();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DynamicThrottleServiceProvider::class];
    }
}
