<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Identity\IdentityHasher;

covers(IdentityHasher::class);

/*
| SPEC-008 AC-14 and AC-15 (ADR-0024): identities are hashed with HMAC-SHA-256
| and a key derived from APP_KEY, because plain SHA-256 of an IPv4 address or
| a numeric id can be reversed by hashing every candidate.
*/

const HASHER_APP_KEY = 'base64:3u4DMqUd5sEWtS4w9UqJ7Qw/qMCRnGFhqRuSN40wNJA=';

it('AC-14: hashes an identity into 64 lowercase hex characters', function (?string $appKey): void {
    expect((new IdentityHasher($appKey))->hash('ip:203.0.113.7'))->toMatch('/\A[0-9a-f]{64}\z/');
})->with(['with an app key' => [HASHER_APP_KEY], 'without one' => [null]]);

it('AC-15: hashes one identity differently under two app keys, and never as plain SHA-256', function (): void {
    $first = (new IdentityHasher(HASHER_APP_KEY))->hash('ip:203.0.113.7');
    $second = (new IdentityHasher('base64:another-key'))->hash('ip:203.0.113.7');

    expect($first)->not->toBe($second)
        ->and([$first, $second])->not->toContain(hash('sha256', 'ip:203.0.113.7'));
});

it('AC-15: gives one identity the same hash every time, and two identities different ones', function (): void {
    $hasher = new IdentityHasher(HASHER_APP_KEY);

    expect($hasher->hash('ip:203.0.113.7'))->toBe((new IdentityHasher(HASHER_APP_KEY))->hash('ip:203.0.113.7'))
        ->not->toBe($hasher->hash('ip:203.0.113.8'));
});

it('AC-15: hashes exactly as ADR-0024 defines, so counters survive upgrades', function (): void {
    // A changed hash would reset every counter, so this value must never change.
    expect((new IdentityHasher(HASHER_APP_KEY))->hash('ip:203.0.113.7'))
        ->toBe('733b11e7765b339531dd351e9730b2c511853ebd08e6ed16180257240f98a11d');
});

it('AC-15: falls back to plain SHA-256 without an app key', function (?string $appKey): void {
    expect((new IdentityHasher($appKey))->hash('ip:203.0.113.7'))->toBe(hash('sha256', 'ip:203.0.113.7'));
})->with(['no key' => [null], 'an empty key' => ['']]);
