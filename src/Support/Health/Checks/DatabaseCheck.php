<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Dashboard\Links\Links;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The package's database (SPEC-037, issue #205), asked as Laravel asks it: the
 * connection's PDO, which connects or throws the driver's error, then
 * Schema::hasTable() for each table the package needs. It errs when no rule can be
 * saved or compiled: the database cannot be reached, or the rules table is missing.
 * Another missing table is a warning, with what stops without it (decision 2). It
 * only reads.
 *
 * @internal
 */
final readonly class DatabaseCheck implements Check
{
    private const string FIX = "Publish the package's migrations with php artisan vendor:publish --tag=dynamic-throttle-migrations, then run php artisan migrate.";

    public function id(): string
    {
        return 'database';
    }

    public function run(HealthContext $context): CheckResult
    {
        // The package's connection, as its models and migrations read it.
        $configured = $context->config->get('dynamic-throttle.database.connection');
        $connection = DB::connection(is_string($configured) && $configured !== '' ? $configured : null);
        $name = (string) $connection->getName();

        try {
            $connection->getPdo();
        } catch (Throwable $exception) {
            // The driver's own error, such as "SQLSTATE[HY000] [2002] Connection refused" (decision 3).
            return CheckResult::error($this->id(), "The database cannot be reached on connection {$name}, so no rule can be saved or compiled, and servers keep the last manifest: {$exception->getMessage()}");
        }

        $schema = Schema::connection($name);
        $missing = [];
        $error = false;

        foreach ($this->tables($context) as $table => [$stops, $errs]) {
            if (! $schema->hasTable($table)) {
                $missing[] = "The {$table} table does not exist, so {$stops}.";
                $error = $error || $errs;
            }
        }

        if ($missing === []) {
            return CheckResult::ok($this->id(), "The database answers on connection {$name}, and the package's tables exist.");
        }

        $message = implode(' ', $missing).' '.self::FIX;

        return $error ? CheckResult::error($this->id(), $message) : CheckResult::warning($this->id(), $message);
    }

    /**
     * The tables the package needs now, in the migrations' order, each with what stops
     * without it and whether that is an error: the hourly metrics only while telemetry
     * is on, and the links only while links are on. A test checks that they are the
     * tables the migrations create.
     *
     * @return array<string, array{string, bool}>
     */
    private function tables(HealthContext $context): array
    {
        $tables = [
            'dynamic_throttle_rules' => ['no rule can be saved, and the compile fails', true],
            'dynamic_throttle_exemptions' => ['the manifest has no exemptions', false],
            'dynamic_throttle_settings' => ['bypass and scale cannot be turned on', false],
            'dynamic_throttle_audit_log' => ['the dashboard and the commands can change nothing, since each change writes an audit entry', false],
        ];

        if ($context->config->get('dynamic-throttle.telemetry.enabled') !== false) {
            $tables['dynamic_throttle_metrics_hourly'] = ['the traffic numbers are never stored', false];
        }

        if (Links::enabled($context->config)) {
            $tables['dynamic_throttle_links'] = ['links can be neither made nor used', false];
        }

        return $tables;
    }
}
