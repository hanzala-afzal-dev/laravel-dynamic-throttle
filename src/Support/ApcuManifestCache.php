<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\CompiledExemption;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\Contracts\LocalManifestCache;
use Afzal\DynamicThrottle\Manifest\ManifestSnapshot;
use Throwable;

/**
 * L1 in APCu (ADR-0007, SPEC-028), where the PHP-FPM workers of a server share
 * the manifest. Every app of the server, and every version of the package, has
 * an entry of its own. A full or failing APCu keeps nothing, and an entry that
 * is not a snapshot is deleted: either way, the request reads the store.
 */
final readonly class ApcuManifestCache implements LocalManifestCache
{
    /** How long an entry is kept: the last known manifest's age, at most (SPEC-028 decision 6). */
    private const int KEEP_SECONDS = 86_400;

    private string $key;

    /**
     * @param  string  $app  the app among those of the server, whose PHP-FPM pools share APCu
     * @param  string  $version  the package's commit, as Composer installed it, else its version
     */
    public function __construct(KeyBuilder $keys, string $app, string $version)
    {
        $this->key = $keys->localManifest($app, $version);
    }

    /**
     * APCu is loaded and enabled here. The CLI needs apc.enable_cli=1.
     */
    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function fetch(): ?ManifestSnapshot
    {
        try {
            $entry = apcu_fetch($this->key, $found);
        } catch (Throwable) {
            // An object APCu cannot read back, such as one whose class changed.
            [$entry, $found] = [null, true];
        }

        if (! $found) {
            return null;
        }

        if ($entry instanceof ManifestSnapshot && $this->listOf($entry->rules, CompiledRule::class) && $this->listOf($entry->exemptions, CompiledExemption::class)) {
            return $entry;
        }

        apcu_delete($this->key);

        return null;
    }

    public function store(ManifestSnapshot $snapshot): void
    {
        try {
            // False when APCu is full, or cannot hold the entry: nothing is kept.
            apcu_store($this->key, $snapshot, self::KEEP_SECONDS);
        } catch (Throwable) {
            // A value PHP cannot serialize: nothing is kept either.
        }
    }

    /**
     * Whether $items is a list of $class objects. The entry holds what was stored, unless
     * something else wrote the key, so its parts are checked as they come back (AC-9).
     *
     * @param  array<mixed>  $items
     * @param  class-string  $class
     */
    private function listOf(array $items, string $class): bool
    {
        if (! array_is_list($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (! $item instanceof $class) {
                return false;
            }
        }

        return true;
    }
}
