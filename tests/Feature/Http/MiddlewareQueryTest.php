<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| SPEC-010 AC-18, the Phase 2 exit criterion: a limited request never queries
| the database (ADR-0007). Throttling::prepare() makes every request read the
| manifest from the cache, as on PHP-FPM.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-18: runs no database query for an allowed or a rejected request, with the manifest compiled in the cache', function (): void {
    Throttling::rule(['max_attempts' => 1]);
    Throttling::rule(['max_attempts' => 5]);
    Throttling::route('api/x');
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $statuses = [$this->get('api/x')->status(), $this->get('api/x')->status()];

    expect($statuses)->toBe([200, 429])
        ->and($queries)->toBe([]);
});
