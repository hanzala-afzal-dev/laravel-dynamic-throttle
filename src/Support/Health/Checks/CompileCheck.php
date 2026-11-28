<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Compiler\ManifestRows;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * The rows the compile skips as invalid, so they apply nowhere (SPEC-037, issue
 * #205): enabled rules, the exemptions it would compile, and settings, each with
 * the compile's own reason. It reads through ManifestRows, as a compile does, which
 * never compiles, writes or reports (decision 4). A warning, as unreached is.
 *
 * @internal
 */
final readonly class CompileCheck implements Check
{
    /** How many rows the warning names. */
    private const int NAMED = 10;

    public function id(): string
    {
        return 'compile';
    }

    public function run(HealthContext $context): CheckResult
    {
        try {
            $skipped = $context->container->make(ManifestRows::class)->skipped();
        } catch (QueryException|PDOException) {
            return CheckResult::skipped($this->id(), 'The database cannot be read: see the database check.');
        }

        if ($skipped === []) {
            return CheckResult::ok($this->id(), 'Every enabled rule, and every exemption and setting the manifest would hold, compiles.');
        }

        $count = count($skipped);
        $named = implode(' ', array_map(fn (Throwable $row): string => $row->getMessage(), array_slice($skipped, 0, self::NAMED)));
        $more = $count > self::NAMED ? ' And '.number_format($count - self::NAMED).' more.' : '';

        return CheckResult::warning($this->id(), 'The compile skips '.number_format($count).' invalid '.($count === 1 ? 'row, so it applies' : 'rows, so they apply')
            ." nowhere: {$named}{$more} Fix or delete ".($count === 1 ? 'it' : 'them').', then run php artisan dynamic-throttle:compile.');
    }
}
