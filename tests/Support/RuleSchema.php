<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Models\Rule;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs the package's own migration, as an app does after publishing it (SPEC-005).
 */
final class RuleSchema
{
    public const string MIGRATIONS = __DIR__.'/../../database/migrations';

    /** @var resource|null the lock this process holds on the shared databases, if any */
    private static $lock;

    public static function migrate(): void
    {
        self::lockSharedDatabase();
        Artisan::call('migrate', ['--path' => self::MIGRATIONS, '--realpath' => true]);
    }

    /**
     * The tables the package's migrations create, newest first, for a test that drops
     * them by hand. Read from the migrations, so a new table is never left behind: on
     * the shared PostgreSQL, a leftover table keeps its unprefixed constraint names,
     * and the next process to migrate fails on them.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        $tables = [];

        foreach (glob(self::MIGRATIONS.'/*.php') ?: [] as $migration) {
            if (preg_match_all("/Schema::create\\('([a-z_]+)'/", (string) file_get_contents($migration), $created) > 0) {
                array_push($tables, ...$created[1]);
            }
        }

        return array_reverse($tables);
    }

    /**
     * Copies the package's migrations into $directory as vendor:publish does: every one
     * again, each under a new date, in their order (issue #206). Not those $except names.
     *
     * @param  string  $day  the copies' date, such as 2026_11_04
     * @param  list<string>  $except  names after the date, such as create_dynamic_throttle_links_table.php
     */
    public static function publish(string $directory, string $day, array $except = []): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $migrations = glob(self::MIGRATIONS.'/*.php') ?: [];
        sort($migrations);

        foreach (array_values($migrations) as $position => $migration) {
            $name = (string) preg_replace('/\A\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($migration));

            if (! in_array($name, $except, true)) {
                copy($migration, sprintf('%s/%s_%06d_%s', $directory, $day, $position + 1, $name));
            }
        }
    }

    public static function rollback(): void
    {
        try {
            Artisan::call('migrate:rollback', ['--path' => self::MIGRATIONS, '--realpath' => true]);
        } finally {
            self::unlock();
        }
    }

    /**
     * Lets another process migrate the shared databases again: rollback() does, and a
     * test that drops its tables itself calls it.
     */
    public static function unlock(): void
    {
        if (self::$lock !== null) {
            flock(self::$lock, LOCK_UN);
            fclose(self::$lock);
            self::$lock = null;
        }
    }

    /**
     * The driver tests' MySQL and PostgreSQL are shared, and the migrations name some
     * constraints without the table prefix, so two processes migrating at once clash
     * under --parallel (CLAUDE.md). One process at a time holds them, from migrate()
     * to rollback(); SQLite, which each test has to itself, never waits.
     */
    private static function lockSharedDatabase(): void
    {
        $connection = config('dynamic-throttle.database.connection') ?? config('database.default');
        $driver = is_string($connection) ? config("database.connections.{$connection}.driver") : null;

        if (self::$lock !== null || ! in_array($driver, ['mysql', 'pgsql'], true)) {
            return;
        }

        $lock = fopen(sys_get_temp_dir().'/dynamic-throttle-shared-schema.lock', 'c');

        if ($lock !== false && flock($lock, LOCK_EX)) {
            self::$lock = $lock;
        }
    }

    /**
     * The fields a rule needs, with no defaults (SPEC-005 AC-13).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function attributes(array $overrides = []): array
    {
        return [
            'name' => 'API',
            'pattern' => 'api/*',
            'scope' => Scope::Ip,
            'max_attempts' => 60,
            'window_seconds' => 60,
            ...$overrides,
        ];
    }

    /**
     * Saves a rule with no default value left, and reads it back (SPEC-005 AC-14).
     *
     * @return array{Rule, array<string, mixed>}
     */
    public static function roundTrip(): array
    {
        $override = ['message' => 'Slow down', 'retry' => ['after' => 30]];
        $id = Rule::create(self::attributes([
            'description' => 'Keeps scrapers off the API.',
            'method' => RequestMethod::Post,
            'scope' => Scope::Header,
            'scope_parameter' => 'X-Api-Key',
            'priority' => 5,
            'mode' => RuleMode::DryRun,
            'on_failure' => FailurePolicy::Closed,
            'on_missing_identity' => MissingIdentity::Skip,
            'response_override' => $override,
            'enabled' => false,
        ]))->getKey();

        return [Rule::query()->findOrFail($id), $override];
    }
}
