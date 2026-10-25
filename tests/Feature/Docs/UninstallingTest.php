<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/*
| docs/guide/uninstalling.md. An app keeps the published config file and
| migrations after it removes the package, so they must load without it. The
| page's migration drops the package's tables, so it must name each table the
| migrations create, a new one included, and drop a table before those it references.
*/

function uninstallingRoot(): string
{
    return dirname(__DIR__, 3);
}

/**
 * The tables the package's migrations create, each with the tables it references.
 *
 * @return array<string, list<string>>
 */
function uninstallingTables(): array
{
    $tables = [];

    foreach (glob(uninstallingRoot().'/database/migrations/*.php') ?: [] as $migration) {
        $source = (string) file_get_contents($migration);
        preg_match_all("/Schema::create\\('([^']+)'/", $source, $created);
        preg_match_all("/constrained\\('([^']+)'\\)/", $source, $referenced);

        foreach ($created[1] as $table) {
            $tables[$table] = $referenced[1];
        }
    }

    return $tables;
}

/**
 * The page's migration that drops the tables, as an app would save it.
 */
function uninstallingMigration(): string
{
    $page = uninstallingRoot().'/docs/guide/uninstalling.md';
    expect($page)->toBeFile();

    preg_match_all('/```php\n(.*?)```/s', (string) file_get_contents($page), $blocks);
    $migrations = array_values(array_filter($blocks[1], fn (string $block): bool => str_contains($block, 'dropIfExists')));
    expect($migrations)->toHaveCount(1);

    return $migrations[0];
}

it('leaves the app a config file and migrations that load without the package: they use only Laravel\'s classes', function (string $file): void {
    $source = (string) file_get_contents(uninstallingRoot().'/'.$file);
    preg_match_all('/^use\s+([^;\s]+)/m', $source, $imports);

    expect($source)->not->toContain('Afzal\\')
        ->and(array_values(array_filter($imports[1], fn (string $class): bool => ! str_starts_with($class, 'Illuminate\\'))))->toBe([]);
})->with(fn (): array => array_map(
    fn (string $path): string => substr($path, strlen(uninstallingRoot()) + 1),
    [uninstallingRoot().'/config/dynamic-throttle.php', ...(glob(uninstallingRoot().'/database/migrations/*.php') ?: [])],
));

it('drops, with the page\'s migration, every table the package\'s migrations create, with rows in them', function (): void {
    $tables = uninstallingTables();
    $directory = sys_get_temp_dir().'/dynamic-throttle-uninstall-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory);
    File::put($directory.'/2026_10_25_000001_drop_dynamic_throttle_tables.php', uninstallingMigration());

    try {
        RuleSchema::migrate();
        $rule = DB::table('dynamic_throttle_rules')->insertGetId([
            'name' => 'API', 'pattern' => 'api/*', 'scope' => 'ip', 'max_attempts' => 60, 'window_seconds' => 60,
        ]);
        DB::table('dynamic_throttle_exemptions')->insert(['rule_id' => $rule, 'subject' => 'ip', 'value' => '203.0.113.7']);

        Artisan::call('migrate', ['--path' => $directory, '--realpath' => true]);

        expect($tables)->toHaveCount(count(glob(uninstallingRoot().'/database/migrations/*.php') ?: []))
            ->and(array_values(array_filter(array_keys($tables), fn (string $table): bool => Schema::hasTable($table))))->toBe([]);
    } finally {
        RuleSchema::unlock();
        File::deleteDirectory($directory);
    }
});

it('drops each table before the tables it references, which MySQL and PostgreSQL require', function (): void {
    $migration = uninstallingMigration();
    $order = [];

    foreach (uninstallingTables() as $table => $references) {
        foreach ($references as $referenced) {
            $order["{$table} before {$referenced}"] = strpos($migration, "'{$table}'") < strpos($migration, "'{$referenced}'");
        }
    }

    expect($order)->not->toBe([])
        ->and(array_keys(array_filter($order, fn (bool $before): bool => ! $before)))->toBe([]);
});

it('is linked from the guide', function (): void {
    expect((string) file_get_contents(uninstallingRoot().'/docs/guide/README.md'))->toContain('](uninstalling.md)');
});
