<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestSnapshot;
use Afzal\DynamicThrottle\Support\ApcuManifestCache;

/**
 * The manifest's entries in APCu, L1, for the SPEC-028 tests: one app of the
 * server, and one version of the package, unless a test names others.
 */
final class LocalManifests
{
    public const string APP = 'app';

    public const string VERSION = 'v1';

    public static function cache(string $app = self::APP, string $version = self::VERSION): ApcuManifestCache
    {
        return new ApcuManifestCache(new KeyBuilder('dt:'), $app, $version);
    }

    public static function key(string $app = self::APP, string $version = self::VERSION): string
    {
        return (new KeyBuilder('dt:'))->localManifest($app, $version);
    }

    /**
     * A snapshot of a manifest with one rule and one exemption, as the compiler writes them.
     */
    public static function snapshot(int $version, int $readAt = FakeClock::T0 * 1_000_000, bool $stale = false): ManifestSnapshot
    {
        $manifest = ManifestCodec::decode(ManifestFixtures::json($version, [[]], [[]]));

        return ManifestSnapshot::of($stale ? $manifest->stale() : $manifest, $readAt);
    }
}
