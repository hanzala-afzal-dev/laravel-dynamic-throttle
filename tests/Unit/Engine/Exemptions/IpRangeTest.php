<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Exemptions\IpRange;

covers(IpRange::class);

/*
| SPEC-009 AC-10, AC-11 and AC-13: the addresses and ranges an ip exemption
| names, and the client addresses they are checked against.
*/

it('AC-11: normalizes an address or a range', function (string $written, string $normalized): void {
    expect((string) IpRange::parse($written))->toBe($normalized);
})->with([
    'a range with host bits set' => ['10.0.0.1/8', '10.0.0.0/8'],
    'a range written normally' => ['10.0.0.0/8', '10.0.0.0/8'],
    'upper-case IPv6' => ['2001:DB8::/32', '2001:db8::/32'],
    'an IPv4 address' => ['203.0.113.7', '203.0.113.7/32'],
    'an IPv6 address' => ['2001:db8::1', '2001:db8::1/128'],
    'IPv6 written in full' => ['2001:0db8:0000:0000:0000:0000:0000:0001/128', '2001:db8::1/128'],
    'an IPv4 prefix inside a byte' => ['10.1.2.3/31', '10.1.2.2/31'],
    'an IPv6 prefix inside a byte' => ['2001:db8::ff/121', '2001:db8::80/121'],
    'every IPv4 address' => ['0.0.0.0/0', '0.0.0.0/0'],
    'every IPv6 address' => ['::/0', '::/0'],
    'an IPv4 address with its prefix' => ['203.0.113.7/32', '203.0.113.7/32'],
]);

it('AC-11: counts an IPv4-mapped or NAT64 address or range as the IPv4 one, as clients are', function (string $written, string $normalized): void {
    expect((string) IpRange::parse($written))->toBe($normalized);
})->with([
    'an IPv4-mapped address' => ['::ffff:203.0.113.7', '203.0.113.7/32'],
    'an IPv4-mapped range' => ['::ffff:203.0.113.0/120', '203.0.113.0/24'],
    'a NAT64 address' => ['64:ff9b::203.0.113.7', '203.0.113.7/32'],
    'a NAT64 range' => ['64:ff9b::203.0.113.0/120', '203.0.113.0/24'],
    'an IPv4-mapped range with host bits set' => ['::ffff:203.0.113.9/120', '203.0.113.0/24'],
    'every IPv4-mapped address' => ['::ffff:0:0/96', '0.0.0.0/0'],
    'every NAT64 address' => ['64:ff9b::/96', '0.0.0.0/0'],
    'a range wider than the IPv4-mapped block, which stays IPv6' => ['::ffff:0:0/95', '::fffe:0:0/95'],
]);

it('keeps a range as its network\'s bytes and its prefix length', function (): void {
    $range = IpRange::parse('203.0.113.9/24');

    expect([$range->network, $range->prefixLength])->toBe([inet_pton('203.0.113.0'), 24]);
});

it('AC-10: rejects what is not an address or a range', function (string $written): void {
    expect(fn (): IpRange => IpRange::parse($written))->toThrow(InvalidArgumentException::class);
})->with([
    'a prefix longer than IPv4 has' => ['10.0.0.0/33'],
    'a prefix longer than IPv6 has' => ['2001:db8::/129'],
    'a word' => ['not-an-ip'],
    'a negative prefix' => ['10.0.0.1/-1'],
    'a prefix with a plus sign' => ['10.0.0.0/+8'],
    'nothing' => [''],
    'a slash without a prefix' => ['10.0.0.0/'],
    'a prefix without an address' => ['/8'],
    'two prefixes' => ['10.0.0.0/8/8'],
    'a prefix that is not a number' => ['10.0.0.0/x'],
    'a fractional prefix' => ['10.0.0.0/8.5'],
    'a zero-padded octet' => ['010.0.0.1'],
    'a space' => [' 10.0.0.0/8'],
    'an IPv6 zone' => ['fe80::1%eth0'],
    'a host name' => ['localhost'],
    'a NUL byte in the address' => ["10.0.0.1\0/8"],
    'a NUL byte after the address' => ["10.0.0.1\0"],
]);

it('AC-13: reads a client address as its bytes, and an IPv4 client that reaches the app as IPv6 as its IPv4 bytes', function (?string $ip, ?string $bytes): void {
    expect(IpRange::address($ip))->toBe($bytes);
})->with([
    'IPv4' => ['203.0.113.9', inet_pton('203.0.113.9')],
    'IPv6' => ['2001:db8::1', inet_pton('2001:db8::1')],
    'IPv4-mapped' => ['::ffff:203.0.113.9', inet_pton('203.0.113.9')],
    'NAT64' => ['64:ff9b::203.0.113.9', inet_pton('203.0.113.9')],
    'no IP' => [null, null],
    'an empty IP' => ['', null],
    'not an IP' => ['unknown', null],
    'a range' => ['203.0.113.0/24', null],
    'a NUL byte' => ["203.0.113.7\0", null],
]);

it('AC-13: cuts an address to a prefix length', function (string $ip, int $prefixLength, string $cut): void {
    expect(IpRange::cut((string) inet_pton($ip), $prefixLength))->toBe(inet_pton($cut));
})->with([
    'IPv4 to /24' => ['203.0.113.200', 24, '203.0.113.0'],
    'IPv4 to /32' => ['203.0.113.200', 32, '203.0.113.200'],
    'IPv4 to /0' => ['203.0.113.200', 0, '0.0.0.0'],
    'IPv4 inside the last byte' => ['203.0.113.255', 25, '203.0.113.128'],
    'IPv4 inside the second byte' => ['10.255.255.255', 9, '10.128.0.0'],
    'IPv6 to /32' => ['2001:db8:ffff::1', 32, '2001:db8::'],
    'IPv6 inside the last byte' => ['2001:db8::ff', 121, '2001:db8::80'],
    'IPv6 to /128' => ['2001:db8::1', 128, '2001:db8::1'],
    'IPv6 to /0' => ['2001:db8::1', 0, '::'],
]);
