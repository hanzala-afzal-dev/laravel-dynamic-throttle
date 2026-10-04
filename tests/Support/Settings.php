<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Enums\SettingName;
use Afzal\DynamicThrottle\Models\AuditEntry;
use Afzal\DynamicThrottle\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The runtime settings along their path (SPEC-013): rows in the table, the
 * manifest the compiler writes, and the audit entries of the commands.
 */
final class Settings
{
    /**
     * Turns bypass on through the model, which compiles the manifest.
     *
     * @param  ?int  $until  Unix seconds, or null for no end time
     */
    public static function bypass(?int $until = null): Setting
    {
        return Setting::create([
            'name' => SettingName::BypassAll,
            'value' => '1',
            'expires_at' => $until === null ? null : Carbon::createFromTimestamp($until),
        ]);
    }

    /**
     * Sets the scale factor through the model, which compiles the manifest.
     *
     * @param  ?int  $until  Unix seconds, or null for no end time
     */
    public static function scale(string $factor, ?int $until = null): Setting
    {
        return Setting::create([
            'name' => SettingName::ScaleFactor,
            'value' => $factor,
            'expires_at' => $until === null ? null : Carbon::createFromTimestamp($until),
        ]);
    }

    /**
     * A settings row written without Eloquent: it can hold values the model would
     * refuse, and writing it compiles nothing.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function insert(array $overrides = []): int
    {
        return (int) DB::table('dynamic_throttle_settings')->insertGetId([
            'name' => 'bypass_all',
            'value' => '1',
            'expires_at' => null,
            'created_at' => null,
            'updated_at' => null,
            ...$overrides,
        ]);
    }

    /**
     * The settings of the stored manifest, decoded. An empty object decodes to [].
     *
     * @return array<array-key, mixed>
     */
    public static function stored(): array
    {
        $settings = Exemptions::stored()['settings'] ?? null;

        return is_array($settings) ? $settings : [];
    }

    /**
     * The audit log, oldest first, as plain values.
     *
     * @return list<array{actor: string, action: string, target: string, before: mixed, after: mixed, ip: ?string, user_agent: ?string}>
     */
    public static function audit(): array
    {
        return AuditEntry::query()->orderBy('id')->get()->map(fn (AuditEntry $entry): array => [
            'actor' => (string) $entry->getAttribute('actor'),
            'action' => (string) $entry->getRawOriginal('action'),
            'target' => (string) $entry->getAttribute('target'),
            'before' => $entry->getAttribute('before'),
            'after' => $entry->getAttribute('after'),
            'ip' => $entry->getAttribute('ip'),
            'user_agent' => $entry->getAttribute('user_agent'),
        ])->values()->all();
    }

    /**
     * A time in UTC, such as "2027-01-01 00:30:00", in Unix seconds.
     */
    public static function at(string $time): int
    {
        return Carbon::parse($time, 'UTC')->getTimestamp();
    }

    /**
     * The CLI's actor in the audit log (ADR-0015): the system user that runs the
     * command, and the user who ran it through sudo.
     */
    public static function cliActor(): string
    {
        $user = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;
        $user = is_string($user) && $user !== '' ? $user : (getenv('USER') ?: 'unknown');
        $sudo = getenv('SUDO_USER');

        return "cli:{$user}".(is_string($sudo) && $sudo !== '' && $sudo !== $user ? " (sudo by {$sudo})" : '');
    }
}
