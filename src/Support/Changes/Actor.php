<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Changes;

use Afzal\DynamicThrottle\Console\CliActor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Who made a change, as its audit entry records it (ADR-0015 point 3): the CLI's
 * system user (SPEC-013), or the dashboard's user, with the request's IP and user
 * agent (SPEC-017).
 *
 * @internal
 */
final readonly class Actor
{
    public function __construct(
        public string $name,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}

    /**
     * The system user that runs the command, with no IP or user agent.
     */
    public static function cli(): self
    {
        return new self(CliActor::name());
    }

    /**
     * The request's user, named as exemptions and the user scope name one,
     * user:App\Models\User:42, so one user has one name in every table (decision 5),
     * or guest where an undefined gate lets a guest in locally.
     */
    public static function of(Request $request): self
    {
        $user = $request->user();
        $id = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;

        $name = match (true) {
            ! $user instanceof Authenticatable => 'guest',
            is_int($id) || is_string($id) => 'user:'.$user::class.':'.$id,
            default => 'user:'.$user::class,
        };

        return new self($name, $request->ip(), $request->userAgent());
    }
}
