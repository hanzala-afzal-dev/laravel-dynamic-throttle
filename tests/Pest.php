<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test layers (ADR-0016)
|--------------------------------------------------------------------------
|
| Unit and Arch tests are plain PHP and stay fast. Feature and Drivers tests
| boot a Laravel application through Orchestra Testbench. The Drivers suite
| needs the docker compose services and is excluded from `composer test`;
| run it with `composer test:drivers`.
|
*/

pest()->extend(TestCase::class)->in('Feature');

pest()->extend(TestCase::class)->group('drivers')->in('Drivers');
