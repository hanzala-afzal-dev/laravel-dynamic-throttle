<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Compiler\CompileResult;
use Afzal\DynamicThrottle\Compiler\LastCompile;
use Afzal\DynamicThrottle\Compiler\ManifestCompileFailed;
use Afzal\DynamicThrottle\Enums\AuditAction;
use Afzal\DynamicThrottle\Enums\SettingName;
use Afzal\DynamicThrottle\Models\AuditEntry;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Models\Setting;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * The changes an admin makes during an incident (ADR-0011, SPEC-013): bypass, the
 * scale factor, and switching one rule off or on (ADR-0015 point 4).
 *
 * Each change and its audit entry are written in one transaction, so neither is
 * kept without the other (ADR-0015 point 3). The models' observer compiles the
 * manifest once, after the commit, as for any change (ADR-0007), and the change
 * reads that compile back, so the caller can tell whether servers will see it.
 * In a surrounding transaction, the compile waits for it to commit. The commands
 * and the JSON API (SPEC-017) use it.
 *
 * @internal
 */
final readonly class EmergencyControls
{
    /** Attempts after a deadlock, which Laravel retries (the review: MySQL deadlocks on two first writes). */
    private const int ATTEMPTS = 3;

    public function __construct(
        private LastCompile $last,
    ) {}

    /**
     * @param  ?CarbonInterface  $until  when bypass ends, or null for no end time
     *
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function bypass(?CarbonInterface $until, Actor $actor): ChangeOutcome
    {
        return $this->setting(SettingName::BypassAll, AuditAction::SettingsBypass, '1', $until, $actor);
    }

    /**
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function endBypass(Actor $actor): ChangeOutcome
    {
        return $this->setting(SettingName::BypassAll, AuditAction::SettingsBypass, null, null, $actor);
    }

    /**
     * @param  int  $percent  the factor as a percentage, from 10 to 1,000
     * @param  ?CarbonInterface  $until  when the factor ends, or null for no end time
     *
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function scale(int $percent, ?CarbonInterface $until, Actor $actor): ChangeOutcome
    {
        return $this->setting(SettingName::ScaleFactor, AuditAction::SettingsScale, ScaleFactor::format($percent), $until, $actor);
    }

    /**
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function endScale(Actor $actor): ChangeOutcome
    {
        return $this->setting(SettingName::ScaleFactor, AuditAction::SettingsScale, null, null, $actor);
    }

    /**
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function setRuleEnabled(Rule $rule, bool $enabled, Actor $actor): ChangeOutcome
    {
        $this->requireTable(new AuditEntry);

        return $this->change($rule->getConnection(), function () use ($rule, $enabled, $actor): bool {
            // Read again, locked: another admin may have changed it since it was loaded (the review).
            $current = Rule::query()->whereKey($rule->getKey())->lockForUpdate()->first();

            if (! $current instanceof Rule || $current->enabled === $enabled) {
                return false;
            }

            $current->enabled = $enabled;
            $current->save();
            $this->audit($actor, $enabled ? AuditAction::RuleEnable : AuditAction::RuleDisable, "rule:{$current->id}", ['enabled' => ! $enabled], ['enabled' => $enabled]);

            return true;
        });
    }

    /**
     * Turns a setting on with a value, or off with null.
     *
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    private function setting(SettingName $name, AuditAction $action, ?string $value, ?CarbonInterface $until, Actor $actor): ChangeOutcome
    {
        $this->requireTable(new Setting);
        $this->requireTable(new AuditEntry);

        return $this->change((new Setting)->getConnection(), function () use ($name, $action, $value, $until, $actor): bool {
            $setting = Setting::query()->where('name', $name->value)->lockForUpdate()->first();
            $before = $setting instanceof Setting ? $this->state($setting) : null;

            if ($value === null) {
                // A setting that has ended is off already (the review).
                if (! $setting instanceof Setting || $this->ended($setting)) {
                    return false;
                }

                $setting->delete();
                $this->audit($actor, $action, "setting:{$name->value}", $before, null);

                return true;
            }

            $setting ??= new Setting(['name' => $name]);
            $setting->fill(['value' => $value, 'expires_at' => $until]);

            if ($setting->exists && ! $setting->isDirty()) {
                return false;
            }

            $setting->save();
            $this->audit($actor, $action, "setting:{$name->value}", $before, $this->state($setting));

            return true;
        });
    }

    /**
     * Makes a change in a transaction, retried after a deadlock, and once more when
     * another process inserted the same setting first, so the last change wins (the
     * review). Then reads back the compile the observer ran when it committed.
     *
     * @param  Closure(): bool  $change  true when it changed something
     */
    private function change(ConnectionInterface $connection, Closure $change): ChangeOutcome
    {
        $this->last->forget();

        try {
            $changed = $connection->transaction($change, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            $changed = $connection->transaction($change, self::ATTEMPTS);
        }

        if ($changed !== true) {
            return ChangeOutcome::unchanged();
        }

        $result = $this->last->result();
        $failure = $this->last->failure();

        return match (true) {
            $result instanceof CompileResult => ChangeOutcome::compiled($result),
            $failure instanceof ManifestCompileFailed => ChangeOutcome::compileFailed($failure),
            // In a surrounding transaction, the observer compiles when it commits.
            default => ChangeOutcome::pending(),
        };
    }

    private function ended(Setting $setting): bool
    {
        return $setting->expires_at !== null && $setting->expires_at->lessThanOrEqualTo(Date::now());
    }

    /**
     * @return array{value: string, expires_at: ?string}
     */
    private function state(Setting $setting): array
    {
        return ['value' => $setting->value, 'expires_at' => $setting->expires_at?->toIso8601String()];
    }

    /**
     * @param  ?array<string, mixed>  $before
     * @param  ?array<string, mixed>  $after
     */
    private function audit(Actor $actor, AuditAction $action, string $target, ?array $before, ?array $after): void
    {
        AuditEntry::query()->create([
            'actor' => $actor->name,
            'action' => $action,
            'target' => $target,
            'before' => $before,
            'after' => $after,
            'ip' => $actor->ip,
            'user_agent' => $actor->userAgent,
        ]);
    }

    /**
     * @throws TableMissing
     */
    private function requireTable(Model $model): void
    {
        if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            throw TableMissing::named($model->getTable());
        }
    }
}
