<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Explanation;

use JsonSerializable;

/**
 * The app's route for the request, and whether it runs dynamic.throttle after
 * authentication (ADR-0004, ADR-0020 point 4).
 *
 * @internal
 */
final readonly class ExplainedRoute implements JsonSerializable
{
    /**
     * @param  list<string>  $middleware  in the order they run, aliases as their classes
     * @param  bool  $throttled  dynamic.throttle runs for it, on the route or as global middleware
     * @param  ?bool  $afterAuth  whether an authentication middleware runs before it, or null when it does not run
     */
    public function __construct(
        public string $uri,
        public ?string $name,
        public array $middleware,
        public bool $throttled,
        public ?bool $afterAuth,
    ) {}

    /**
     * @return array{uri: string, name: ?string, middleware: list<string>, throttled: bool, after_auth: ?bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'uri' => $this->uri,
            'name' => $this->name,
            'middleware' => $this->middleware,
            'throttled' => $this->throttled,
            'after_auth' => $this->afterAuth,
        ];
    }
}
