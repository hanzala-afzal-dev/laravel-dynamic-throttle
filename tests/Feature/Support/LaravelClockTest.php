<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Support\LaravelClock;
use Illuminate\Support\Carbon;

covers(LaravelClock::class);

it('AC-14: follows Laravel time travel, to the microsecond', function (): void {
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00.250000', 'UTC'));

    expect((new LaravelClock)->microseconds())->toBe(1_798_761_600_250_000);
});

it('moves with the application clock', function (): void {
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'UTC'));
    $before = (new LaravelClock)->microseconds();

    $this->travel(90)->seconds();

    expect((new LaravelClock)->microseconds() - $before)->toBe(90_000_000);
});
