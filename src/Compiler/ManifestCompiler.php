<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Compiler;

use Afzal\DynamicThrottle\Engine\Exemptions\IpRange;
use Afzal\DynamicThrottle\Engine\Identity\IdentityHasher;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Enums\SettingName;
use Afzal\DynamicThrottle\Manifest\CompiledExemption;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\Contracts\ManifestRebuilder;
use Afzal\DynamicThrottle\Manifest\InvalidManifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestSettings;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Models\Setting;
use Afzal\DynamicThrottle\Support\ScaleFactor;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use JsonException;
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
        $rows = Rule::query()->where('enabled', true)->orderBy('priority')->orderBy('id')->toBase()->get();
        $rules = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $row = (array) $row;

            try {
                $rules[] = CompiledRule::fromArray($this->fromRow($row));
            } catch (InvalidArgumentException $exception) {
                $skipped++;
                $id = is_int($row['id'] ?? null) || is_string($row['id'] ?? null) ? $row['id'] : '?';
                report(new InvalidRuleSkipped("Rule {$id} was skipped: ".$exception->getMessage(), previous: $exception));
            }
        }

        [$exemptions, $skippedExemptions] = $this->compileExemptions(array_map(fn (CompiledRule $rule): string => $rule->id, $rules));
        $settings = $this->compileSettings();

        // Greater than every earlier version, even after the cache was flushed (SPEC-006 AC-3).
        $version = max($this->storedVersion() + 1, (int) Date::now()->getTimestampMs());

        if (! $this->cache->forever($this->keys->manifest(), ManifestCodec::encode($version, $rules, $exemptions, $settings))) {
            throw new RuntimeException('The cache store did not accept the manifest.');
        }

        return new CompileResult($version, count($rules), $skipped, count($exemptions), $skippedExemptions);
    }

    /**
     * The exemptions a request can use, in id order: those of the compiled rules and
     * the global ones, until they expire (SPEC-009 AC-9). Invalid rows are skipped and
     * reported, as rules are (AC-10).
     *
     * @param  list<string>  $ruleIds  the compiled rules
     * @return array{list<CompiledExemption>, int} the exemptions, and how many rows were skipped
     */
    private function compileExemptions(array $ruleIds): array
    {
        $model = new Exemption;

        // Limits keep working before the app runs the migration that adds exemptions.
        if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            // Publishing again copies every migration again, with new dates, so the message says to drop the copies.
            report(new ExemptionsTableMissing("The {$model->getTable()} table does not exist, so the manifest has no exemptions. Publish the package's migrations with php artisan vendor:publish --tag=dynamic-throttle-migrations, delete the new copies of those you had already run, then run php artisan migrate."));

            return [[], 0];
        }

        $compiled = array_fill_keys($ruleIds, true);
        $now = Date::now()->getTimestamp();
        $hasher = $this->hasher ?? Container::getInstance()->make(IdentityHasher::class);
        $exemptions = [];
        $skipped = 0;

        foreach ($model->newQuery()->orderBy('id')->toBase()->get() as $row) {
            $row = (array) $row;
            $rule = is_int($row['rule_id']) || is_string($row['rule_id']) ? (string) $row['rule_id'] : null;

            // A disabled, invalid or deleted rule's exemptions can apply to no request.
            if ($rule !== null && ! isset($compiled[$rule])) {
                continue;
            }

            try {
                $exemption = $this->fromExemptionRow($row, $rule, $hasher);
            } catch (InvalidArgumentException $exception) {
                $skipped++;
                $id = is_int($row['id']) || is_string($row['id']) ? $row['id'] : '?';
                report(new InvalidExemptionSkipped("Exemption {$id} was skipped: ".$exception->getMessage(), previous: $exception));

                continue;
            }

            if ($exemption->expiresAt === null || $exemption->expiresAt > $now) {
                $exemptions[] = $exemption;
            }
        }

        return [$exemptions, $skipped];
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
     * The settings that are on and have not ended, from the settings table, and the rows
     * that are not valid settings (SPEC-013 AC-2, AC-3). It only reads, so
     * dynamic-throttle:status compares it with the manifest.
     *
     * @return array{ManifestSettings, list<InvalidSettingSkipped>}
     *
     * @throws SettingsTableMissing
     */
    public function settings(): array
    {
        $model = new Setting;

        if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            // Publishing again copies every migration again, with new dates, so the message says to drop the copies.
            throw new SettingsTableMissing("The {$model->getTable()} table does not exist, so the manifest has no runtime settings. Publish the package's migrations with php artisan vendor:publish --tag=dynamic-throttle-migrations, delete the new copies of those you had already run, then run php artisan migrate.");
        }

        $on = [];
        $skipped = [];

        foreach ($model->newQuery()->orderBy('id')->toBase()->get() as $row) {
            $row = (array) $row;

            try {
                [$name, $percent, $until] = $this->fromSettingRow($row);
            } catch (InvalidArgumentException $exception) {
                $id = is_int($row['id']) || is_string($row['id']) ? $row['id'] : '?';
                $skipped[] = new InvalidSettingSkipped("Setting {$id} was skipped: ".$exception->getMessage(), previous: $exception);

                continue;
            }

            $on[$name->value] = [$percent, $until];
        }

        $bypass = $on[SettingName::BypassAll->value] ?? null;
        $scale = $on[SettingName::ScaleFactor->value] ?? null;
        $settings = new ManifestSettings(
            bypass: $bypass !== null,
            bypassUntil: $bypass[1] ?? null,
            scale: $scale[0] ?? null,
            scaleUntil: $scale[1] ?? null,
        );

        // A setting applies until its end time, and not at it.
        return [$settings->at(Date::now()->getTimestamp()), $skipped];
    }

    /**
     * A settings row's name, its factor as a percentage for scale_factor, and its end
     * time in Unix seconds.
     *
     * @param  array<array-key, mixed>  $row
     * @return array{SettingName, ?int, ?int}
     *
     * @throws InvalidArgumentException naming the bad column
     */
    private function fromSettingRow(array $row): array
    {
        $name = (is_string($row['name']) ? SettingName::tryFrom($row['name']) : null)
            ?? throw new InvalidArgumentException('name must be one of bypass_all, scale_factor, got '.(is_string($row['name']) ? "\"{$row['name']}\"" : get_debug_type($row['name'])).'.');
        $value = is_string($row['value']) ? $row['value'] : throw new InvalidArgumentException("{$name->value}'s value must be a string, got ".get_debug_type($row['value']).'.');

        $percent = match ($name) {
            SettingName::BypassAll => $value === '1' ? null : throw new InvalidArgumentException("bypass_all's value must be 1, got \"{$value}\"."),
            SettingName::ScaleFactor => $this->scalePercent($value),
        };

        return [$name, $percent, $this->expiresAt($row['expires_at'])];
    }

    /**
     * @throws InvalidArgumentException
     */
    private function scalePercent(string $value): int
    {
        try {
            return ScaleFactor::percent($value);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException("scale_factor's value must be from 0.1 to 10, with at most two decimals, got \"{$value}\".", $exception->getCode(), previous: $exception);
        }
    }

    /**
     * An exemptions row as the manifest stores it. A user or custom value is hashed,
     * so the manifest never holds one (ADR-0024), and a range is normalized.
     *
     * @param  array<array-key, mixed>  $row
     *
     * @throws InvalidArgumentException naming the bad column
     */
    private function fromExemptionRow(array $row, ?string $rule, IdentityHasher $hasher): CompiledExemption
    {
        $subject = (is_string($row['subject']) ? ExemptionSubject::tryFrom($row['subject']) : null)
            ?? throw new InvalidArgumentException('subject must be one of ip, user, custom, got '.(is_string($row['subject']) ? "\"{$row['subject']}\"" : get_debug_type($row['subject'])).'.');
        $value = is_string($row['value']) && $row['value'] !== '' ? $row['value'] : throw new InvalidArgumentException('value must not be empty.');
        $parameter = $row['subject_parameter'];

        // Only custom exemptions name a scope: a parameter left on another row is ignored.
        if ($subject === ExemptionSubject::Custom && (! is_string($parameter) || preg_match('/\A[a-z0-9_-]{1,64}\z/', $parameter) !== 1)) {
            throw new InvalidArgumentException('subject_parameter must be a custom scope\'s name, 1 to 64 characters from a-z, 0-9, "_" and "-", got '.(is_string($parameter) ? "\"{$parameter}\"" : get_debug_type($parameter)).'.');
        }

        return new CompiledExemption(
            id: is_int($row['id']) || is_string($row['id']) ? (string) $row['id'] : throw new InvalidArgumentException('id must be an integer.'),
            rule: $rule,
            subject: $subject,
            parameter: $subject === ExemptionSubject::Custom ? $parameter : null,
            match: match ($subject) {
                ExemptionSubject::Ip => $this->range($value),
                // Class names hold no colon, so the first one ends the class.
                ExemptionSubject::User => preg_match('/\A[^:]+:.+\z/s', $value) === 1
                    ? $hasher->hash($value)
                    : throw new InvalidArgumentException("value must be a user as Class:id, such as App\\Models\\User:42, got \"{$value}\"."),
                ExemptionSubject::Custom => $hasher->hash($value),
            },
            expiresAt: $this->expiresAt($row['expires_at']),
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    private function range(string $value): string
    {
        try {
            return (string) IpRange::parse($value);
        } catch (InvalidArgumentException $exception) {
            // Escaped, as IpRange's own message is: a NUL byte makes a log binary to grep.
            $printable = addcslashes($value, "\0..\37");

            throw new InvalidArgumentException("value must be an IP address or a CIDR range, such as 10.0.0.0/8, got \"{$printable}\".", $exception->getCode(), previous: $exception);
        }
    }

    /**
     * Unix seconds. The models store dates in UTC (StoresTimesInUtc).
     *
     * @throws InvalidArgumentException
     */
    private function expiresAt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        try {
            return is_string($value) ? Date::parse($value, 'UTC')->getTimestamp() : throw new InvalidArgumentException('not a string');
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('expires_at must be a date, got '.(is_string($value) ? "\"{$value}\"" : get_debug_type($value)).'.', $exception->getCode(), previous: $exception);
        }
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

    /**
     * A database row in the manifest's shape. Drivers disagree on types (MySQL and
     * SQLite can return numbers as strings), so numbers are normalized here and
     * everything else is left to CompiledRule to judge.
     *
     * @param  array<array-key, mixed>  $row
     * @return array<string, mixed>
     */
    private function fromRow(array $row): array
    {
        return [
            'id' => is_int($row['id']) ? (string) $row['id'] : $row['id'],
            'name' => $row['name'],
            'method' => $row['method'],
            'pattern' => $row['pattern'],
            'scope' => $row['scope'],
            'scope_parameter' => $row['scope_parameter'],
            'max_attempts' => $this->integer($row, 'max_attempts'),
            'window_seconds' => $this->integer($row, 'window_seconds'),
            'priority' => $this->integer($row, 'priority'),
            'mode' => $row['mode'],
            'on_failure' => $row['on_failure'],
            'on_missing_identity' => $row['on_missing_identity'],
            'response_override' => $this->responseOverride($row['response_override']),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function integer(array $row, string $field): mixed
    {
        $value = $row[$field];

        return is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1 ? (int) $value : $value;
    }

    private function responseOverride(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('response_override must be a JSON object, and is not valid JSON.');
        }
    }
}
