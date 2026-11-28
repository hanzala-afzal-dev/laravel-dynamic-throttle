<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Compiler;

use Afzal\DynamicThrottle\Engine\Identity\IdentityHasher;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\Contracts\ManifestRebuilder;
use Afzal\DynamicThrottle\Manifest\InvalidManifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestSettings;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use Throwable;

/**
 * Compiles the rules, exemptions and settings tables into the manifest requests
 * read (ADR-0007, SPEC-006, SPEC-009, SPEC-013). Runs on admin actions, the command
 * line, the scheduler, and after the response of a request that found the manifest
 * missing. Never before a response.
 */
final readonly class ManifestCompiler implements ManifestRebuilder
{
    public function __construct(
        private Repository $cache,
        private KeyBuilder $keys,
        private int $lockSeconds = 30,
        private int $lockWaitSeconds = 5,
        // The container passes the app's. Without one, the app's is looked up, so
        // exemption values are always hashed as requests hash them (ADR-0024).
        private ?IdentityHasher $hasher = null,
    ) {}

    public function rebuild(): void
    {
        $this->compile();
    }

    /**
     * @throws ManifestCompileFailed with nothing written
     */
    public function compile(): CompileResult
    {
        try {
            $store = $this->cache->getStore();

            // A store without locks, such as APCu, is per-server anyway (SPEC-006).
            if (! $store instanceof LockProvider) {
                return $this->compileNow();
            }

            $result = $store->lock($this->keys->manifestLock(), $this->lockSeconds)
                ->block($this->lockWaitSeconds, fn (): CompileResult => $this->compileNow());

            // block() returns the callback's result, which Laravel types as mixed.
            return $result instanceof CompileResult ? $result : throw new RuntimeException('The compile lock returned no result.');
        } catch (Throwable $exception) {
            throw ManifestCompileFailed::because($exception);
        }
    }

    private function compileNow(): CompileResult
    {
        $rows = $this->rows();
        [$rules, $skipped] = $rows->rules();

        foreach ($skipped as $invalid) {
            report($invalid);
        }

        $read = $rows->exemptions(array_map(fn (CompiledRule $rule): string => $rule->id, $rules));

        // Limits keep working before the app runs the migration that adds exemptions.
        if ($read instanceof ExemptionsTableMissing) {
            report($read);
            $read = [[], []];
        }

        [$exemptions, $skippedExemptions] = $read;

        foreach ($skippedExemptions as $invalid) {
            report($invalid);
        }

        $settings = $this->compileSettings();

        // Greater than every earlier version, even after the cache was flushed (SPEC-006 AC-3).
        $version = max($this->storedVersion() + 1, (int) Date::now()->getTimestampMs());

        if (! $this->cache->forever($this->keys->manifest(), ManifestCodec::encode($version, $rules, $exemptions, $settings))) {
            throw new RuntimeException('The cache store did not accept the manifest.');
        }

        return new CompileResult($version, count($rules), count($skipped), count($exemptions), count($skippedExemptions));
    }

    /**
     * The settings for the manifest. A missing table and invalid rows are reported,
     * and compile without them (SPEC-013 AC-3).
     */
    private function compileSettings(): ManifestSettings
    {
        try {
            [$settings, $skipped] = $this->settings();
        } catch (SettingsTableMissing $missing) {
            // Limits keep working before the app runs the migration that adds settings.
            report($missing);

            return ManifestSettings::none();
        }

        foreach ($skipped as $exception) {
            report($exception);
        }

        return $settings;
    }

    /**
     * The settings that are on and have not ended, and the rows that are not valid
     * settings (SPEC-013 AC-2, AC-3). It only reads, so dynamic-throttle:status compares
     * it with the manifest.
     *
     * @return array{ManifestSettings, list<InvalidSettingSkipped>}
     *
     * @throws SettingsTableMissing
     */
    public function settings(): array
    {
        return $this->rows()->settings();
    }

    /**
     * The tables' rows as the manifest holds them, read and checked, with the same
     * hasher requests use (ADR-0024).
     */
    private function rows(): ManifestRows
    {
        return new ManifestRows($this->hasher);
    }

    private function storedVersion(): int
    {
        $json = $this->cache->get($this->keys->manifest());

        try {
            return is_string($json) ? ManifestCodec::decode($json)->version : 0;
        } catch (InvalidManifest) {
            return 0;
        }
    }
}
