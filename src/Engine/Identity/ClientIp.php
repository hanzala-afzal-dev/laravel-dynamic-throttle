<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Identity;

use InvalidArgumentException;

/**
 * The client IP as an identity (ADR-0009, SPEC-008). IPv4 is used whole. IPv6
 * is grouped by prefix, because one client usually controls a whole /64 and
 * could otherwise rotate addresses to get fresh counters.
 */
final class ClientIp
{
    /** Every request without an IP shares this identity. */
    public const string UNKNOWN = 'unknown';

    /** ::ffff:0:0/96, an IPv4 client on a dual-stack socket, in the last 4 bytes. */
    private const string IPV4_MAPPED = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    /**
     * 64:ff9b::/96, the well-known NAT64 prefix (RFC 6052): an IPv4 client that a
     * translator in front of an IPv6-only server presents, in the last 4 bytes.
     */
    private const string NAT64 = "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00";

    /**
     * @param  ?string  $ip  as Request::ip() returns it, so the app's trusted proxies decide it
     * @param  int  $ipv6Prefix  scopes.ip.ipv6_prefix, from 1 to 128
     */
    public static function group(?string $ip, int $ipv6Prefix): string
    {
        if ($ipv6Prefix < 1 || $ipv6Prefix > 128) {
            throw new InvalidArgumentException("The IPv6 prefix must be from 1 to 128, got {$ipv6Prefix}.");
        }

        if ($ip === null || $ip === '') {
            return self::UNKNOWN;
        }

        $bytes = inet_pton($ip);

        // An IPv4 address, or something that is not an IP at all, is used as it is: it is hashed anyway.
        if ($bytes === false || strlen($bytes) === 4) {
            return $ip;
        }

        // An IPv4 client that reaches the app as IPv6 counts as its IPv4 address. inet_ntop() cannot fail on 4 bytes.
        if (str_starts_with($bytes, self::IPV4_MAPPED) || str_starts_with($bytes, self::NAT64)) {
            return (string) inet_ntop(substr($bytes, 12)); // @pest-mutate-ignore: RemoveStringCast
        }

        $wholeBytes = intdiv($ipv6Prefix, 8);
        $network = substr($bytes, 0, $wholeBytes);
        $remainingBits = $ipv6Prefix % 8;

        if ($remainingBits > 0) {
            $network .= chr(ord($bytes[$wholeBytes]) & (0xFF << (8 - $remainingBits)));
        }

        return inet_ntop(str_pad($network, 16, "\0")).'/'.$ipv6Prefix;
    }
}
