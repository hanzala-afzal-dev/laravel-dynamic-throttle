<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test layers (ADR-0016)
|--------------------------------------------------------------------------
|
| Unit and Arch tests are plain PHP and stay fast. Feature, Contract and
| Drivers tests boot a Laravel application through Orchestra Testbench.
|
| - Contract: the shared LimiterStore suite (SPEC-002). `composer test` runs
|   it on local stores; `composer test:drivers` adds every service store.
| - Drivers: needs the docker compose services, excluded from `composer test`.
|
*/

pest()->extend(TestCase::class)->in('Feature');

pest()->extend(TestCase::class)->group('contract')->in('Contract');

pest()->extend(TestCase::class)->group('drivers')->in('Drivers');
