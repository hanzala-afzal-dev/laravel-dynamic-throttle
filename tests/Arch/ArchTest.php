<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Architecture rules
|--------------------------------------------------------------------------
|
| Several CLAUDE.md hard rules are enforced here, so they hold even when
| nobody remembers them. Never weaken a rule to make code pass: if a rule
| is wrong, change the ADR first.
|
| Two Pest limits, both verified with planted probe classes:
| - `not->toUse()` silently passes when expect() receives an array of
|   namespaces, so every namespace gets its own rule.
| - Arch rules never see built-in PHP classes (PDO, DateTime, ...), so
|   those are covered by the source scans at the bottom of this file.
|
| The rules only see direct dependencies, so every request-path namespace
| gets its own rules. A namespace's rules cover its sub-namespaces (checked
| with a probe in Engine\Matching), so Engine\Matching and Engine\Stacking
| fall under the engine's (SPEC-007 AC-18). When SPEC-008 adds scopes
| outside Engine, give them their own rules.
|
*/

arch('every source file declares strict types')
    ->expect('Afzal\DynamicThrottle')
    ->toUseStrictTypes();

arch('no debugging leftovers or dangerous PHP functions')
    ->preset()->php();

arch('no insecure functions such as md5, sha1, rand, eval or unserialize')
    ->preset()->security();

$wallClock = [
    'time', 'microtime', 'hrtime', 'gettimeofday',
    'date', 'gmdate', 'idate', 'getdate', 'localtime', 'mktime', 'gmmktime', 'strtotime',
    'date_create', 'date_create_immutable', 'now', 'today',
    Carbon::class, CarbonImmutable::class, Illuminate\Support\Carbon::class, Date::class,
];

arch('engine code never reads the wall clock directly (ADR-0006)')
    ->expect('Afzal\DynamicThrottle\Engine')
    ->not->toUse($wallClock);

arch('the manifest read path never reads the wall clock directly (SPEC-006 AC-19)')
    ->expect('Afzal\DynamicThrottle\Manifest')
    ->not->toUse($wallClock);

$databaseAccess = ['Illuminate\Database', DB::class, Schema::class, 'Afzal\DynamicThrottle\Models'];

arch('engine code never touches the database (ADR-0007)')
    ->expect('Afzal\DynamicThrottle\Engine')
    ->not->toUse($databaseAccess);

arch('the middleware never touches the database (ADR-0007)')
    ->expect('Afzal\DynamicThrottle\Http\Middleware')
    ->not->toUse($databaseAccess);

arch('request handling never touches the database (ADR-0007, SPEC-007 AC-18)')
    ->expect('Afzal\DynamicThrottle\Http')
    ->not->toUse($databaseAccess);

arch('the manifest read path never touches the database (ADR-0007, SPEC-006 AC-19)')
    ->expect('Afzal\DynamicThrottle\Manifest')
    ->not->toUse($databaseAccess);

arch('the manifest read path reaches the compiler only through ManifestRebuilder (SPEC-006 AC-19)')
    ->expect('Afzal\DynamicThrottle\Manifest')
    ->not->toUse('Afzal\DynamicThrottle\Compiler');

arch('enums never touch the database, so the request path can use them (SPEC-005 AC-16)')
    ->expect('Afzal\DynamicThrottle\Enums')
    ->not->toUse($databaseAccess);

arch('enums are string-backed, because their values are stored (SPEC-005 AC-16)')
    ->expect('Afzal\DynamicThrottle\Enums')
    ->toBeStringBackedEnums();

dataset('built-in classes the arch rules cannot see', [
    'a "now" date in the engine bypasses travel() (ADR-0006)' => [
        'src/Engine', '/new\s+\\\\?DateTime(?:Immutable)?\s*\(\s*(?:[\'"]now[\'"]\s*)?\)/i',
    ],
    'PDO in the engine (ADR-0007)' => ['src/Engine', '/\bPDO\b/'],
    'PDO in the middleware (ADR-0007)' => ['src/Http/Middleware', '/\bPDO\b/'],
    'PDO in request handling (SPEC-007 AC-18)' => ['src/Http', '/\bPDO\b/'],
]);

test('the request path stays free of', function (string $directory, string $pattern): void {
    $root = __DIR__.'/../../'.$directory;
    $offenders = [];

    if (is_dir($root)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([]);
})->with('built-in classes the arch rules cannot see');
