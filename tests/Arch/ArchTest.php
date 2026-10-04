<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\DynamicThrottle;
use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Failure\Cooldown;
use Afzal\DynamicThrottle\Engine\Failure\ReportThrottle;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Models\AuditEntry;
use Afzal\DynamicThrottle\Support\ApcuFailureMemory;
use Afzal\DynamicThrottle\Support\ApcuManifestCache;
use Afzal\DynamicThrottle\Support\EmergencyControls;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

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
| fall under the engine's (SPEC-007 AC-18), and so do Engine\Identity
| (SPEC-008 AC-18) and Engine\Exemptions (SPEC-009 AC-21), whose expiry check
| reads the Clock. Reading identities from the request lives in Http, which
| has its own rules. The events the middleware dispatches (SPEC-011 AC-9)
| are built on the request path too, so Events has its own.
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

arch('request handling, the middleware included, reads the time only through the Clock (SPEC-010 AC-19)')
    ->expect('Afzal\DynamicThrottle\Http')
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

arch('the facade never touches the database (SPEC-008 AC-18)')
    ->expect('Afzal\DynamicThrottle\Facades')
    ->not->toUse($databaseAccess);

arch('the scope registry behind the facade never touches the database (SPEC-008 AC-18)')
    ->expect(DynamicThrottle::class)
    ->not->toUse($databaseAccess);

arch('the public contracts never touch the database (SPEC-008 AC-18)')
    ->expect('Afzal\DynamicThrottle\Contracts')
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

arch('events are final readonly classes (SPEC-011 AC-9)')
    ->expect('Afzal\DynamicThrottle\Events')
    ->toBeFinal()
    ->toBeReadonly();

arch('events never touch the database (ADR-0007, SPEC-011 AC-9)')
    ->expect('Afzal\DynamicThrottle\Events')
    ->not->toUse($databaseAccess);

arch('events read no clock, not even the package\'s Clock (SPEC-011 AC-9)')
    ->expect('Afzal\DynamicThrottle\Events')
    ->not->toUse([...$wallClock, Clock::class]);

arch('events carry plain values, never the request, so a queued listener can serialize them (SPEC-011 AC-9)')
    ->expect('Afzal\DynamicThrottle\Events')
    ->not->toUse([Request::class, SymfonyRequest::class, RequestFacade::class, 'request']);

/*
| SPEC-012 AC-11: what the process remembers about failures lives in the
| failure memories (ProcessFailureMemory, and APCu through ApcuFailureMemory),
| never in the classes that decide a request. A readonly class cannot hold
| static state either.
*/

arch('the middleware keeps no state between requests (SPEC-012 AC-11)')
    ->expect(DynamicThrottleMiddleware::class)
    ->toBeReadonly();

arch('the evaluator keeps no state between requests (SPEC-012 AC-11)')
    ->expect(RuleEvaluator::class)
    ->toBeReadonly();

arch('the cooldown keeps its state in the failure memories (SPEC-012 AC-11)')
    ->expect(Cooldown::class)
    ->toBeReadonly();

arch('the report throttle keeps its state in the failure memories (SPEC-012 AC-11)')
    ->expect(ReportThrottle::class)
    ->toBeReadonly();

arch('only ApcuFailureMemory and ApcuManifestCache call APCu, with keys from KeyBuilder (SPEC-012 AC-11, SPEC-028 AC-13)')
    ->expect(['apcu_fetch', 'apcu_store', 'apcu_add', 'apcu_cas', 'apcu_inc', 'apcu_dec', 'apcu_entry', 'apcu_exists', 'apcu_delete', 'apcu_clear_cache'])
    ->toOnlyBeUsedIn([ApcuFailureMemory::class, ApcuManifestCache::class]);

/*
| SPEC-028 AC-13: L1, the manifest in APCu, is on the request path. It lives
| in Support, next to ApcuFailureMemory, so it gets rules of its own, and the
| manifest's read path reaches it only through LocalManifestCache.
*/

arch('the manifest\'s APCu cache never reads the wall clock (SPEC-028 AC-13)')
    ->expect(ApcuManifestCache::class)
    ->not->toUse($wallClock);

arch('the manifest\'s APCu cache never touches the database (SPEC-028 AC-13)')
    ->expect(ApcuManifestCache::class)
    ->not->toUse($databaseAccess);

arch('the manifest read path reaches APCu only through LocalManifestCache (SPEC-028 AC-13)')
    ->expect('Afzal\DynamicThrottle\Manifest')
    ->not->toUse(ApcuManifestCache::class);

/*
| SPEC-013 AC-23: every change the commands make goes through EmergencyControls,
| which writes the change and its audit entry in one transaction.
*/

arch('only EmergencyControls writes the audit log (SPEC-013 AC-23)')
    ->expect(AuditEntry::class)
    ->toOnlyBeUsedIn([EmergencyControls::class]);

dataset('built-in classes the arch rules cannot see', [
    'a "now" date in the engine bypasses travel() (ADR-0006)' => [
        'src/Engine', '/new\s+\\\\?DateTime(?:Immutable)?\s*\(\s*(?:[\'"]now[\'"]\s*)?\)/i',
    ],
    'a "now" date in request handling bypasses travel() (SPEC-010 AC-19)' => [
        'src/Http', '/new\s+\\\\?DateTime(?:Immutable)?\s*\(\s*(?:[\'"]now[\'"]\s*)?\)/i',
    ],
    'PDO in the engine (ADR-0007)' => ['src/Engine', '/\bPDO\b/'],
    'PDO in the middleware (ADR-0007)' => ['src/Http/Middleware', '/\bPDO\b/'],
    'PDO in request handling (SPEC-007 AC-18)' => ['src/Http', '/\bPDO\b/'],
    'a "now" date in the events (SPEC-011 AC-9)' => [
        'src/Events', '/new\s+\\\\?DateTime(?:Immutable)?\s*\(\s*(?:[\'"]now[\'"]\s*)?\)/i',
    ],
    'PDO in the events (SPEC-011 AC-9)' => ['src/Events', '/\bPDO\b/'],
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
