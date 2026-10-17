<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Telemetry\FeedEntry;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryOutcome;

covers(FeedEntry::class);

/*
| SPEC-030: one block or would-block in the live feed, as the Redis buffer keeps it.
*/

it('SPEC-030 AC-4: goes through JSON and back whole, nulls and non-ASCII values included', function (): void {
    $entries = [
        new FeedEntry(1_800_000_000_123, '7', TelemetryOutcome::Blocked, 'POST', 'orders/{order}', '2001:db8::1', 'App\Models\User:42', str_repeat('a', 64)),
        new FeedEntry(0, '12', TelemetryOutcome::WouldBlock, 'GET', null, null, null, str_repeat('b', 64)),
        new FeedEntry(5, '3', TelemetryOutcome::Blocked, 'GET', 'café/{ü}', '203.0.113.7', 'App\Models\User:team:5', str_repeat('c', 64)),
    ];

    expect(array_map(fn (FeedEntry $entry): ?FeedEntry => FeedEntry::fromJson($entry->toJson()), $entries))->toEqual($entries);
});

it('SPEC-030 AC-4: holds no newline in its JSON, so a list can carry several in one argument', function (): void {
    $entry = new FeedEntry(1, '1', TelemetryOutcome::Blocked, 'GET', "a\nb", "c\nd", "e\nf", str_repeat('a', 64));

    expect($entry->toJson())->not->toContain("\n")
        ->and(FeedEntry::fromJson($entry->toJson()))->toEqual($entry);
});

it('SPEC-030 AC-4: keeps only a block or a would-block, at a time from 0 on', function (TelemetryOutcome $outcome, int $time, string $message): void {
    expect(fn (): FeedEntry => new FeedEntry($time, '1', $outcome, 'GET', null, null, null, str_repeat('a', 64)))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a counted request' => [TelemetryOutcome::Counted, 1, 'The feed keeps only blocks and would-blocks, got counted.'],
    'a skipped rule' => [TelemetryOutcome::Skipped, 1, 'The feed keeps only blocks and would-blocks, got skipped.'],
    'an ignored rule' => [TelemetryOutcome::Ignored, 1, 'The feed keeps only blocks and would-blocks, got ignored.'],
    'a time before 1970' => [TelemetryOutcome::Blocked, -1, 'A feed entry\'s time is Unix milliseconds from 0, got -1.'],
]);

it('SPEC-030 AC-4: reads anything but a whole entry as none', function (string $json): void {
    expect(FeedEntry::fromJson($json))->toBeNull();
})->with([
    'not JSON' => ['{'],
    'a list' => ['[1,2]'],
    'no time' => ['{"r":"1","o":"blocked","m":"GET","p":null,"i":null,"u":null,"h":"x"}'],
    'a time as text' => ['{"t":"1","r":"1","o":"blocked","m":"GET","p":null,"i":null,"u":null,"h":"x"}'],
    'a negative time' => ['{"t":-1,"r":"1","o":"blocked","m":"GET","p":null,"i":null,"u":null,"h":"x"}'],
    'a counted outcome' => ['{"t":1,"r":"1","o":"counted","m":"GET","p":null,"i":null,"u":null,"h":"x"}'],
    'an unknown outcome' => ['{"t":1,"r":"1","o":"maybe","m":"GET","p":null,"i":null,"u":null,"h":"x"}'],
    'a rule as a number' => ['{"t":1,"r":1,"o":"blocked","m":"GET","p":null,"i":null,"u":null,"h":"x"}'],
    'an ip as a number' => ['{"t":1,"r":"1","o":"blocked","m":"GET","p":null,"i":7,"u":null,"h":"x"}'],
    'no identity' => ['{"t":1,"r":"1","o":"blocked","m":"GET","p":null,"i":null,"u":null}'],
]);

it('SPEC-030 AC-8: gives the API\'s fields', function (): void {
    $entry = new FeedEntry(1_800_000_000_123, '7', TelemetryOutcome::WouldBlock, 'POST', 'login', '203.0.113.7', null, str_repeat('a', 64));

    expect($entry->toArray())->toBe([
        'time' => 1_800_000_000_123,
        'rule' => '7',
        'outcome' => 'would_block',
        'method' => 'POST',
        'route' => 'login',
        'ip' => '203.0.113.7',
        'user' => null,
        'identity' => str_repeat('a', 64),
    ]);
});
