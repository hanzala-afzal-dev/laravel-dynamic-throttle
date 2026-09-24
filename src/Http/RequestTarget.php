<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Http;

use Illuminate\Http\Request;

/**
 * The method and path a request's rules are matched on (SPEC-007), taken the
 * way Laravel's router takes them, so an encoded path or a form's _method
 * cannot avoid a rule that the route itself does not avoid.
 */
final readonly class RequestTarget
{
    public function __construct(
        public string $method,
        /** URL-decoded, without slashes at either end, and "/" for the root. */
        public string $path,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self($request->getMethod(), $request->decodedPath());
    }
}
