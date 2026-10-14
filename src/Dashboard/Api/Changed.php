<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\Compiler\CompileResult;
use Afzal\DynamicThrottle\Compiler\ManifestCompileFailed;
use Afzal\DynamicThrottle\Support\ChangeOutcome;
use Illuminate\Http\JsonResponse;

/**
 * What a change answers (SPEC-017): what it saved, whether it changed anything,
 * and what the compile that followed did. A change that is saved but does not
 * compile still succeeds: servers keep the last manifest until the next compile
 * (decision 12).
 *
 * @internal
 */
final class Changed
{
    /**
     * @param  array<string, mixed>  $data  what the change saved, under its own key
     */
    public static function response(array $data, ChangeOutcome $outcome, int $status = 200): JsonResponse
    {
        return new JsonResponse([...$data, 'changed' => $outcome->changed, 'manifest' => self::manifest($outcome)], $status);
    }

    /**
     * @return array{compiled: bool, version?: int, error?: string}|null null when nothing changed
     */
    private static function manifest(ChangeOutcome $outcome): ?array
    {
        if ($outcome->compiled instanceof CompileResult) {
            return ['compiled' => true, 'version' => $outcome->compiled->version];
        }

        if ($outcome->compileFailed instanceof ManifestCompileFailed) {
            return ['compiled' => false, 'error' => $outcome->compileFailed->getMessage()];
        }

        return null;
    }
}
