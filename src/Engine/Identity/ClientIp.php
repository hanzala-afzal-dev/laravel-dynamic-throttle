<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Identity;

use LogicException;

final class ClientIp
{
    public const string UNKNOWN = 'unknown';

    public static function group(?string $ip, int $ipv6Prefix): string
    {
        throw new LogicException('Not implemented yet (SPEC-008).');
    }
}
