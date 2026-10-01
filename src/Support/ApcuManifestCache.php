<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\Contracts\LocalManifestCache;
use Afzal\DynamicThrottle\Manifest\ManifestSnapshot;
use LogicException;

/**
 * L1 in APCu (ADR-0007, SPEC-028), where the PHP-FPM workers of a server share
 * the manifest.
 */
final readonly class ApcuManifestCache implements LocalManifestCache
{
    public function __construct(
        private KeyBuilder $keys,
        /** The app among those of the server, whose PHP-FPM pools share APCu. */
        private string $app,
        /** The package's commit, as Composer installed it, else its version. */
        private string $version,
    ) {}

    /**
     * APCu is loaded and enabled here. The CLI needs apc.enable_cli=1.
     */
    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function fetch(): ?ManifestSnapshot
    {
        throw new LogicException('Not implemented yet (SPEC-028): '.$this->keys->prefix.$this->app.$this->version);
    }

    public function store(ManifestSnapshot $snapshot): void
    {
        throw new LogicException('Not implemented yet (SPEC-028): '.$snapshot->version);
    }
}
