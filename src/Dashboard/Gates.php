<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;

/**
 * What the request's user may do on the dashboard (SPEC-018, ADR-0015). Until the app
 * defines either gate, the local environment gets in, guests too, as with Horizon
 * (decision 2). Once it defines one, the gates it defines decide alone, and one it
 * left out denies (the security review). They are read on each request, so the order
 * of the service providers does not matter.
 *
 * @internal
 */
final readonly class Gates
{
    public const string VIEW = 'viewDynamicThrottle';

    public const string MANAGE = 'manageDynamicThrottle';

    public function __construct(
        private Gate $gate,
        private Application $app,
    ) {}

    /**
     * Managing implies viewing, so an app can define the manager gate alone (decision 7).
     *
     * @return array{view: bool, manage: bool}
     */
    public function abilities(): array
    {
        $defined = $this->gate->has(self::VIEW) || $this->gate->has(self::MANAGE);
        $manage = $this->allows(self::MANAGE, $defined);

        return ['view' => $manage || $this->allows(self::VIEW, $defined), 'manage' => $manage];
    }

    private function allows(string $ability, bool $defined): bool
    {
        if ($this->gate->has($ability)) {
            return $this->gate->allows($ability);
        }

        return ! $defined && $this->app->environment('local');
    }
}
