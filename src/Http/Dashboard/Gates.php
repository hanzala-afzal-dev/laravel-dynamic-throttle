<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Http\Dashboard;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;

/**
 * What the request's user may do on the dashboard (SPEC-018, ADR-0015). A gate the
 * app has not defined allows the local environment only, guests too, as Horizon's
 * does (decision 2). A gate the app defines decides alone. Both are read on each
 * request, so the order of the service providers does not matter.
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
        $manage = $this->allows(self::MANAGE);

        return ['view' => $manage || $this->allows(self::VIEW), 'manage' => $manage];
    }

    private function allows(string $ability): bool
    {
        return $this->gate->has($ability) ? $this->gate->allows($ability) : $this->app->environment('local');
    }
}
