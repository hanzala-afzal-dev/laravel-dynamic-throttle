<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Exemptions;

use InvalidArgumentException;
use LogicException;
use Stringable;

/**
 * An IP address or CIDR range an exemption names (SPEC-009).
 */
final readonly class IpRange implements Stringable
{
    private function __construct(
        public string $network,
        public int $prefixLength,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function parse(string $range): self
    {
        throw new LogicException("Not implemented yet (SPEC-009): {$range}");
    }

    public static function address(?string $ip): ?string
    {
        throw new LogicException("Not implemented yet (SPEC-009): {$ip}");
    }

    public static function cut(string $address, int $prefixLength): string
    {
        throw new LogicException("Not implemented yet (SPEC-009): {$prefixLength}");
    }

    public function __toString(): string
    {
        throw new LogicException("Not implemented yet (SPEC-009): {$this->prefixLength}");
    }
}
