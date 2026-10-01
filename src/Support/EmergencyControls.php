<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Compiler\ManifestCompileFailed;
use Afzal\DynamicThrottle\Compiler\ManifestCompiler;
use Afzal\DynamicThrottle\Enums\AuditAction;
use Afzal\DynamicThrottle\Enums\SettingName;
use Afzal\DynamicThrottle\Models\AuditEntry;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The changes an admin makes during an incident (ADR-0011, SPEC-013): bypass, the
 * scale factor, and switching one rule off or on (ADR-0015 point 6).
 *
 * Each change and its audit entry are written in one transaction, so neither is
 * kept without the other (ADR-0015 point 3). The manifest is then compiled once,
 * so the caller can tell whether servers will see the change. The models' observer
 * stays quiet meanwhile, or it would compile twice. The commands use it, and the
 * JSON API (SPEC-017) and the dashboard (SPEC-021) will.
 *
 * @internal
 */
final readonly class EmergencyControls
{
    public function __construct(
        private ManifestCompiler $compiler,
    ) {}

    /**
     * @param  ?CarbonInterface  $until  when bypass ends, or null for no end time
     *
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function bypass(?CarbonInterface $until, string $actor): ChangeOutcome
    {
        return $this->setting(SettingName::BypassAll, AuditAction::SettingsBypass, '1', $until, $actor);
    }

    /**
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function endBypass(string $actor): ChangeOutcome
    {
        return $this->setting(SettingName::BypassAll, AuditAction::SettingsBypass, null, null, $actor);
    }

    /**
     * @param  int  $percent  the factor as a percentage, from 10 to 1,000
     * @param  ?CarbonInterface  $until  when the factor ends, or null for no end time
     *
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function scale(int $percent, ?CarbonInterface $until, string $actor): ChangeOutcome
    {
        return $this->setting(SettingName::ScaleFactor, AuditAction::SettingsScale, ScaleFactor::format($percent), $until, $actor);
    }

    /**
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function endScale(string $actor): ChangeOutcome
    {
        return $this->setting(SettingName::ScaleFactor, AuditAction::SettingsScale, null, null, $actor);
    }

    /**
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function setRuleEnabled(Rule $rule, bool $enabled, string $actor): ChangeOutcome
    {
        $this->requireTable(new AuditEntry);

        $changed = Model::withoutEvents(fn (): bool => $rule->getConnection()->transaction(function () use ($rule, $enabled, $actor): bool {
            if ($rule->enabled === $enabled) {
                return false;
            }

            $rule->enabled = $enabled;
            $rule->save();
            $this->audit($actor, $enabled ? AuditAction::RuleEnable : AuditAction::RuleDisable, "rule:{$rule->id}", ['enabled' => ! $enabled], ['enabled' => $enabled]);

            return true;
        }));

        return $changed ? $this->compile() : ChangeOutcome::unchanged();
    }

    /**
     * Turns a setting on with a value, or off with null.
     *
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    private function setting(SettingName $name, AuditAction $action, ?string $value, ?CarbonInterface $until, string $actor): ChangeOutcome
    {
        $this->requireTable(new Setting);
        $this->requireTable(new AuditEntry);

        $changed = Model::withoutEvents(fn (): bool => (new Setting)->getConnection()->transaction(function () use ($name, $action, $value, $until, $actor): bool {
            $setting = Setting::query()->where('name', $name->value)->lockForUpdate()->first();
            $before = $setting instanceof Setting ? $this->state($setting) : null;

            if ($value === null) {
                if (! $setting instanceof Setting) {
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
        }));

        return $changed ? $this->compile() : ChangeOutcome::unchanged();
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
    private function audit(string $actor, AuditAction $action, string $target, ?array $before, ?array $after): void
    {
        AuditEntry::query()->create([
            'actor' => $actor,
            'action' => $action,
            'target' => $target,
            'before' => $before,
            'after' => $after,
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

    private function compile(): ChangeOutcome
    {
        try {
            return ChangeOutcome::compiled($this->compiler->compile());
        } catch (ManifestCompileFailed $failure) {
            return ChangeOutcome::compileFailed($failure);
        }
    }
}
