<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Architecture rules
|--------------------------------------------------------------------------
|
| Several CLAUDE.md hard rules are enforced here, so they hold even when
| nobody remembers them. Never weaken a rule to make code pass: if a rule
| is wrong, change the ADR first.
|
*/

arch('every source file declares strict types')
    ->expect('Afzal\DynamicThrottle')
    ->toUseStrictTypes();

arch('no debugging leftovers or dangerous PHP functions')
    ->preset()->php();

arch('no insecure functions such as md5, sha1, rand, eval or unserialize')
    ->preset()->security();

arch('engine code never reads the wall clock directly (ADR-0006)')
    ->expect('Afzal\DynamicThrottle\Engine')
    ->not->toUse(['time', 'microtime', 'hrtime', 'date', 'gmdate', 'strtotime']);

// One rule per namespace on purpose: Pest silently passes `not->toUse()`
// when expect() receives an array of namespaces (verified with probe classes).
$databaseAccess = ['Illuminate\Database', DB::class, 'Afzal\DynamicThrottle\Models'];

arch('engine code never touches the database (ADR-0007)')
    ->expect('Afzal\DynamicThrottle\Engine')
    ->not->toUse($databaseAccess);

arch('the middleware never touches the database (ADR-0007)')
    ->expect('Afzal\DynamicThrottle\Http\Middleware')
    ->not->toUse($databaseAccess);
