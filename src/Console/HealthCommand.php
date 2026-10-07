<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Illuminate\Console\Command;
use LogicException;

/**
 * SPEC-016: not built yet.
 */
final class HealthCommand extends Command
{
    protected $signature = 'dynamic-throttle:health
        {--json : Print the report as JSON}
        {--strict : Exit 1 on a warning too}';

    protected $description = 'Check the config, the routes, the store and what the rules saw';

    public function handle(): int
    {
        throw new LogicException('SPEC-016: not built yet.');
    }
}
