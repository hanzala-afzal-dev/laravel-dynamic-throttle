<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Identity\ClientIp;

covers(ClientIp::class);

/*
| SPEC-008 AC-1 and AC-2: the client IP as an identity. IPv4 is used whole and
| IPv6 is grouped by prefix, so a client cannot get a new counter by rotating
| addresses inside the network it controls (ADR-0009).
*/

it('AC-1: uses an IPv4 address whole', function (): void {
    expect(ClientIp::group('203.0.113.7', 64))->toBe('203.0.113.7')
        ->and(ClientIp::group('203.0.113.8', 64))->toBe('203.0.113.8');
});

it('AC-2: groups an IPv6 address by the prefix', function (string $ip, int $prefix, string $group): void {
    expect(ClientIp::group($ip, $prefix))->toBe($group);
})->with([
    'the default /64' => ['2001:db8:1:2::1', 64, '2001:db8:1:2::/64'],
    'another address in the same /64' => ['2001:db8:1:2:ffff::9', 64, '2001:db8:1:2::/64'],
    'the next /64' => ['2001:db8:1:3::1', 64, '2001:db8:1:3::/64'],
    'a /128 keeps the whole address' => ['2001:db8:1:2::1', 128, '2001:db8:1:2::1/128'],
    'a /48' => ['2001:db8:1:2::1', 48, '2001:db8:1::/48'],
    'another /64 in the same /48' => ['2001:db8:1:3::1', 48, '2001:db8:1::/48'],
    'a prefix inside a 16-bit group' => ['2001:db8:1:12::1', 60, '2001:db8:1:10::/60'],
    'a prefix inside a byte' => ['2001:db8:1:1f::1', 62, '2001:db8:1:1c::/62'],
    'a /1' => ['ffff::1', 1, '8000::/1'],
    'an address written out in full' => ['2001:0db8:0001:0002:0000:0000:0000:0001', 64, '2001:db8:1:2::/64'],
    'upper case' => ['2001:DB8:1:2::1', 64, '2001:db8:1:2::/64'],
    // Ten zero bytes, like an IPv4-mapped address, but without the ffff after them.
    'the loopback' => ['::1', 64, '::/64'],
    'the loopback in full' => ['::1', 128, '::1/128'],
    'an IPv4-compatible address' => ['::203.0.113.7', 64, '::/64'],
]);

it('AC-2: counts an IPv4-mapped IPv6 address as its IPv4 address', function (): void {
    expect(ClientIp::group('::ffff:203.0.113.7', 64))->toBe('203.0.113.7')
        ->and(ClientIp::group('::ffff:203.0.113.7', 128))->toBe('203.0.113.7');
});

it('AC-2: gives every request without an IP the same identity', function (?string $ip): void {
    expect(ClientIp::group($ip, 64))->toBe(ClientIp::UNKNOWN)
        ->and(ClientIp::UNKNOWN)->toBe('unknown');
})->with(['no IP' => [null], 'an empty IP' => ['']]);

it('keeps a value that is not an IP address as it is, because it is hashed anyway', function (): void {
    expect(ClientIp::group('not-an-ip', 64))->toBe('not-an-ip');
});

it('rejects a prefix outside 1 to 128, whatever the address', function (string $ip, int $prefix): void {
    expect(fn (): string => ClientIp::group($ip, $prefix))
        ->toThrow(InvalidArgumentException::class, "The IPv6 prefix must be from 1 to 128, got {$prefix}.");
})->with([
    '0 for IPv6' => ['2001:db8::1', 0],
    '129 for IPv6' => ['2001:db8::1', 129],
    '0 for IPv4' => ['203.0.113.7', 0],
]);
