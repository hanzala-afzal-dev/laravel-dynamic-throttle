<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Afzal\DynamicThrottle\Compiler\ManifestCompiler;
use Afzal\DynamicThrottle\Compiler\SettingsTableMissing;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestSettings;
use Afzal\DynamicThrottle\Support\LimiterStoreFactory;
use Afzal\DynamicThrottle\Support\ScaleFactor;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Shows what servers apply, from the manifest in the cache store, and checks the
 * config (SPEC-013, ADR-0020 point 1). It exits 1 only for an invalid config, so a
 * deploy script can stop on it, and it never writes, compiles or rebuilds anything.
 */
final class StatusCommand extends Command
{
    protected $signature = 'dynamic-throttle:status';

    protected $description = 'Show what servers apply, and check the config';

    public function handle(Config $config, CacheFactory $cache): int
    {
        $this->components->twoColumnDetail('Enabled', $config->get('dynamic-throttle.enabled') === true ? 'yes' : 'no: DYNAMIC_THROTTLE_ENABLED is off');

        try {
            // Built as requests build them, so a config mistake throws here as it would there.
            $driver = $this->laravel->make(LimiterStoreFactory::class)->describe();
            $keys = $this->laravel->make(KeyBuilder::class);
            $store = $config->get('dynamic-throttle.store');
            $manifestCache = $cache->store(is_string($store) && $store !== '' ? $store : null);
        } catch (InvalidArgumentException $exception) {
            $this->error('The config is invalid: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Driver', $driver);
        $now = Date::now();
        $manifest = $this->manifest($manifestCache, $keys);

        if ($manifest instanceof Manifest) {
            $this->components->twoColumnDetail('Bypass', $manifest->settings->bypass ? $this->until('on', $manifest->settings->bypassUntil, $now) : 'off');
            $this->components->twoColumnDetail('Scale', $manifest->settings->scale !== null ? $this->until('factor '.ScaleFactor::format($manifest->settings->scale), $manifest->settings->scaleUntil, $now) : 'off');
        }

        $this->compareWithDatabase($manifest?->settings, $now);

        return self::SUCCESS;
    }

    /**
     * The manifest servers read, as a request reads it from the cache store, without
     * L0 or L1, and without rebuilding a missing one.
     */
    private function manifest(CacheRepository $cache, KeyBuilder $keys): ?Manifest
    {
        try {
            $json = $cache->get($keys->manifest());
            $manifest = is_string($json) ? ManifestCodec::decode($json) : null;
        } catch (Throwable $exception) {
            $this->components->twoColumnDetail('Manifest', 'cannot be read: '.$exception->getMessage());

            return null;
        }

        if (! $manifest instanceof Manifest) {
            $this->components->twoColumnDetail('Manifest', 'missing: run php artisan dynamic-throttle:compile');

            return null;
        }

        $rules = count($manifest->rules());
        $exemptions = count($manifest->exemptions->all());
        $this->components->twoColumnDetail('Manifest', "version {$manifest->version}: {$rules} ".Str::plural('rule', $rules).", {$exemptions} ".Str::plural('exemption', $exemptions));

        return $manifest;
    }

    private function until(string $setting, ?int $until, CarbonInterface $now): string
    {
        if ($until === null) {
            return "{$setting}, with no end time";
        }

        $end = Date::createFromTimestamp($until)->setTimezone($now->getTimezone())->format('Y-m-d H:i:s T');

        if ($until <= $now->getTimestamp()) {
            return "ended at {$end}";
        }

        return "{$setting} until {$end}, ".CarbonInterval::seconds($until - $now->getTimestamp())->cascade()->forHumans(['parts' => 2]).' left';
    }

    /**
     * Warns when the database holds settings no compile has written yet. Reading the
     * database never fails the command: during an outage, the cache is what matters.
     */
    private function compareWithDatabase(?ManifestSettings $compiled, CarbonInterface $now): void
    {
        try {
            [$saved] = $this->laravel->make(ManifestCompiler::class)->settings();
        } catch (SettingsTableMissing $missing) {
            $this->warn($missing->getMessage());

            return;
        } catch (Throwable $exception) {
            $this->warn('The database cannot be read: '.$exception->getMessage());

            return;
        }

        if ($compiled instanceof ManifestSettings && $saved->toArray() !== $compiled->at($now->getTimestamp())->toArray()) {
            $this->warn('The database holds settings that no compile has written yet: run php artisan dynamic-throttle:compile.');
        }
    }
}
