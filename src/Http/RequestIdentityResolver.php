<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Http;

use Afzal\DynamicThrottle\DynamicThrottle;
use Afzal\DynamicThrottle\Engine\Contracts\IdentityResolver;
use Afzal\DynamicThrottle\Engine\Identity\IdentityHasher;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use LogicException;

/**
 * The identities one request counts under (SPEC-008).
 */
final class RequestIdentityResolver implements IdentityResolver
{
    public function __construct(
        private readonly Request $request,
        private readonly AuthFactory $auth,
        private readonly DynamicThrottle $scopes,
        private readonly Container $container,
        private readonly IdentityHasher $hasher,
        private readonly IdentitySettings $settings,
    ) {}

    public function resolve(CompiledRule $rule): ?string
    {
        throw new LogicException('Not implemented yet (SPEC-008).');
    }
}
