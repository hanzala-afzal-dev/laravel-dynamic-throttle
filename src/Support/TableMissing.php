<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use RuntimeException;

/**
 * A table EmergencyControls writes does not exist yet, because the app has not
 * run the package's newest migrations (SPEC-013). Nothing was written.
 *
 * @internal
 */
final class TableMissing extends RuntimeException
{
    public static function named(string $table): self
    {
        // Publishing again copies every migration again, with new dates, so the message says to drop the copies.
        return new self("The {$table} table does not exist. Publish the package's migrations with php artisan vendor:publish --tag=dynamic-throttle-migrations, delete the new copies of those you had already run, then run php artisan migrate.");
    }
}
