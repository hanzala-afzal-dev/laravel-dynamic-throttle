<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Exemptions;

use Afzal\DynamicThrottle\Engine\Identity\ClientIp;
use InvalidArgumentException;
use Stringable;

/**
 * An IP address or CIDR range an ip exemption names (SPEC-009): its network's
 * bytes and a prefix length. An IPv4 client counts as IPv4 even when it reaches
 * the app as IPv6 (SPEC-008), so a range written that way counts as IPv4 too.
 */
final readonly class IpRange implements Stringable
{
    /**
     * @param  string  $network  4 or 16 bytes, with every bit after the prefix cleared
     * @param  int  $prefixLength  0 to 32, or 0 to 128
     */
    private function __construct(
        public string $network,
        public int $prefixLength,
    ) {}

    /**
     * An address, such as "203.0.113.7", or a range, such as "10.0.0.0/8", IPv4 or
     * IPv6. Bits after the prefix may be set: "10.0.0.1/8" is "10.0.0.0/8".
     *
     * @throws InvalidArgumentException for anything else
     */
    public static function parse(string $range): self
    {
        $parts = explode('/', $range, 2);
        $prefix = $parts[1] ?? null;
        $bytes = self::bytes($parts[0]);

        if ($bytes === false) {
            throw self::invalid($range);
        }

        $bits = strlen($bytes) * 8;

        // Digits only: no sign, no fraction, no leading zero, and nothing around them.
        if ($prefix !== null && preg_match('/\A(?:0|[1-9]\d{0,2})\z/', $prefix) !== 1) {
            throw self::invalid($range);
        }

        $prefixLength = $prefix === null ? $bits : (int) $prefix;

        if ($prefixLength > $bits) {
            throw self::invalid($range);
        }

        // Only a range inside ::ffff:0:0/96 or 64:ff9b::/96 is IPv4 as a whole.
        $unwrapped = ClientIp::unwrap($bytes);

        if ($prefixLength >= 96 && $unwrapped !== $bytes) {
            return new self(self::cut($unwrapped, $prefixLength - 96), $prefixLength - 96);
        }

        return new self(self::cut($bytes, $prefixLength), $prefixLength);
    }

    /**
     * A client IP as Request::ip() gives it, as bytes: 4 for IPv4, including an IPv4
     * client that reaches the app as IPv6, and 16 for IPv6. Null for no IP.
     */
    public static function address(?string $ip): ?string
    {
        $bytes = $ip === null ? false : self::bytes($ip);

        return $bytes === false ? null : ClientIp::unwrap($bytes);
    }

    /**
     * The address with every bit after the first $prefixLength cleared.
     */
    public static function cut(string $address, int $prefixLength): string
    {
        $wholeBytes = intdiv($prefixLength, 8);
        $cut = substr($address, 0, $wholeBytes);
        $remainingBits = $prefixLength % 8;

        if ($remainingBits > 0) {
            $cut .= chr(ord($address[$wholeBytes]) & (0xFF << (8 - $remainingBits)));
        }

        return str_pad($cut, strlen($address), "\0");
    }

    /**
     * The normal form: "10.0.0.0/8", "2001:db8::/32", "203.0.113.7/32".
     */
    public function __toString(): string
    {
        return inet_ntop($this->network).'/'.$this->prefixLength;
    }

    /**
     * inet_pton() throws a ValueError for a NUL byte, which no address holds.
     */
    private static function bytes(string $address): string|false
    {
        return str_contains($address, "\0") ? false : inet_pton($address);
    }

    private static function invalid(string $range): InvalidArgumentException
    {
        $printable = addcslashes($range, "\0..\37");

        return new InvalidArgumentException("\"{$printable}\" is not an IP address or a CIDR range.");
    }
}
