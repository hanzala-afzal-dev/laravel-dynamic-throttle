<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Failure\ReportThrottle;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;

covers(ReportThrottle::class);

/*
| SPEC-012 decision 5 through the middleware: a cause stops at the package's
| own code, which takes in the service provider, so the route's middleware is
| never part of it.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
    Exceptions::fake();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('reports a config mistake once per cooldown, whichever route runs into it', function (): void {
    Throttling::rule();
    Throttling::route('api/a');
    Throttling::route('api/b', [SubstituteBindings::class, 'dynamic.throttle']);
    config()->set('dynamic-throttle.store', 'missing');

    $this->get('api/a');
    $this->get('api/b');

    expect(Exceptions::reported())->toHaveCount(1);
});
