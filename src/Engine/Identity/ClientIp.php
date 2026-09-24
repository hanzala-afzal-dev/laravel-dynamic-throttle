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

        // An IPv4 address, or something that is not an IP at all, is used as it is: it is hashed anyway.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }

        $bytes = (string) inet_pton($ip);

        // ::ffff:203.0.113.7 is an IPv4 client on a dual-stack socket.
        if (str_starts_with($bytes, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($bytes, 12));
        }

        $wholeBytes = intdiv($ipv6Prefix, 8);
        $network = substr($bytes, 0, $wholeBytes);
        $remainingBits = $ipv6Prefix % 8;

        if ($remainingBits > 0) {
            $network .= chr(ord($bytes[$wholeBytes]) & (0xFF << (8 - $remainingBits)) & 0xFF);
        }

        return inet_ntop(str_pad($network, 16, "\0")).'/'.$ipv6Prefix;
    }
}
