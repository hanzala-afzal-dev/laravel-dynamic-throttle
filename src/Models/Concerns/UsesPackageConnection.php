<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Models\Concerns;

/**
 * The package's tables live on the connection from
 * config('dynamic-throttle.database.connection'), or the app's default (ADR-0020
 * point 7).
 */
trait UsesPackageConnection
{
    /**
     * A connection set explicitly, by Model::on() or by Eloquent when it loads models
     * from a query, wins.
     */
    public function getConnectionName(): ?string
    {
        // The parent turns an enum connection name into its value.
        $explicit = parent::getConnectionName();

        if ($explicit !== null) {
            return $explicit;
        }

        $connection = config('dynamic-throttle.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
}
