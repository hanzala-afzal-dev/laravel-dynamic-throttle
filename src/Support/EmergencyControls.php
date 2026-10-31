<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Compiler\CompileResult;
use Afzal\DynamicThrottle\Compiler\LastCompile;
use Afzal\DynamicThrottle\Compiler\ManifestCompileFailed;
use Afzal\DynamicThrottle\Enums\AuditAction;
use Afzal\DynamicThrottle\Enums\SettingName;
use Afzal\DynamicThrottle\Models\AuditEntry;
use Afzal\DynamicThrottle\Models\DashboardLink;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Models\Setting;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * The changes an admin makes (ADR-0011, ADR-0015): bypass, the scale factor and
 * switching one rule off or on, from the CLI and the JSON API (SPEC-013), and
 * creating, changing and deleting rules, and creating and deleting exemptions,
 * from the JSON API (SPEC-017). It is the
 * only writer of the audit log (SPEC-013 AC-23).
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
     * Saves a new rule, with its rule.create entry (SPEC-017). The outcome is about the
     * rule, as the database stored it.
     *
     * @param  array<string, mixed>  $attributes  validated (SPEC-018)
     *
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function createRule(array $attributes, Actor $actor): ChangeOutcome
    {
        $this->requireTable(new AuditEntry);
        $created = null;

        // Created inside the transaction, so a retry after a deadlock inserts afresh.
        $outcome = $this->change((new Rule)->getConnection(), function () use ($attributes, $actor, &$created): bool {
            $created = Rule::query()->create($attributes)->refresh();
            $this->audit($actor, AuditAction::RuleCreate, "rule:{$created->id}", null, $this->ruleState($created));

            return true;
        });

        return $created instanceof Rule ? $outcome->about($created) : $outcome;
    }

    /**
     * Changes a rule, with an entry for the fields that changed: rule.enable or
     * rule.disable when only enabled did, as the CLI records it, and rule.update
     * otherwise (SPEC-017 decision 7). The changes are worked out from the rule as
     * read again, locked, so another admin's change in between is never lost.
     *
     * @param  Closure(Rule): array<string, mixed>  $changes  the attributes to fill, validated against the locked rule; it throws to change nothing
     *
     * @throws ModelNotFoundException when the rule was deleted meanwhile
     * @throws TableMissing|Throwable from the database or from $changes, with nothing written
     */
    public function updateRule(Rule $rule, Closure $changes, Actor $actor): ChangeOutcome
    {
        $this->requireTable(new AuditEntry);
        $current = $rule;

        $outcome = $this->change($rule->getConnection(), function () use ($rule, $changes, $actor, &$current): bool {
            $current = Rule::query()->whereKey($rule->getKey())->lockForUpdate()->firstOrFail();
            $before = $current->attributesToArray();
            $current->fill($changes($current));
            $dirty = array_keys($current->getDirty());

            if ($dirty === []) {
                return false;
            }

            $current->save();
            $action = match (true) {
                $dirty !== ['enabled'] => AuditAction::RuleUpdate,
                $current->enabled => AuditAction::RuleEnable,
                default => AuditAction::RuleDisable,
            };
            $this->audit($actor, $action, "rule:{$current->id}", Arr::only($before, $dirty), Arr::only($current->attributesToArray(), $dirty));

            return true;
        });

        return $outcome->about($current);
    }

    /**
     * Deletes a rule and the exemptions that name it, with one rule.delete entry that
     * counts them (SPEC-017 decision 8): listing them could outgrow the column.
     *
     * @param  Closure(Rule): void  $check  given the locked rule; it throws to delete nothing, such as for a missing confirm
     *
     * @throws ModelNotFoundException when the rule was deleted meanwhile
     * @throws TableMissing|Throwable from the database or from $check, with nothing written
     */
    public function deleteRule(Rule $rule, Closure $check, Actor $actor): ChangeOutcome
    {
        $this->requireTable(new AuditEntry);
        $current = $rule;

        $outcome = $this->change($rule->getConnection(), function () use ($rule, $check, $actor, &$current): bool {
            $current = Rule::query()->whereKey($rule->getKey())->lockForUpdate()->firstOrFail();
            $check($current);
            $before = $this->ruleState($current);
            // Without the foreign key's help, which SQLite may not enforce, and without a compile for each.
            $exemptions = $current->exemptions()->delete();
            $current->delete();
            $this->audit($actor, AuditAction::RuleDelete, "rule:{$current->id}", [...$before, 'exemptions_deleted' => $exemptions], null);

            return true;
        });

        return $outcome->about($current);
    }

    /**
     * Saves a new exemption, with its exemption.create entry (SPEC-017).
     *
     * @param  array<string, mixed>  $attributes  validated (SPEC-018)
     *
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function createExemption(array $attributes, Actor $actor): ChangeOutcome
    {
        $this->requireTable(new AuditEntry);
        $created = null;

        // Created inside the transaction, so a retry after a deadlock inserts afresh.
        $outcome = $this->change((new Exemption)->getConnection(), function () use ($attributes, $actor, &$created): bool {
            $created = Exemption::query()->create($attributes)->refresh();
            $this->audit($actor, AuditAction::ExemptionCreate, "exemption:{$created->id}", null, $this->exemptionState($created));

            return true;
        });

        return $created instanceof Exemption ? $outcome->about($created) : $outcome;
    }

    /**
     * Deletes an exemption, with its exemption.delete entry (SPEC-017).
     *
     * @throws ModelNotFoundException when the exemption was deleted meanwhile
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function deleteExemption(Exemption $exemption, Actor $actor): ChangeOutcome
    {
        $this->requireTable(new AuditEntry);
        $current = $exemption;

        $outcome = $this->change($exemption->getConnection(), function () use ($exemption, $actor, &$current): bool {
            $current = Exemption::query()->whereKey($exemption->getKey())->lockForUpdate()->firstOrFail();
            $current->delete();
            $this->audit($actor, AuditAction::ExemptionDelete, "exemption:{$current->id}", $this->exemptionState($current), null);

            return true;
        });

        return $outcome->about($current);
    }

    /**
     * Saves a new dashboard link, with its link.create entry (SPEC-034). The entry says
     * who it is for, what it allows, when it ends and who made it, never the token or
     * its hash. Links are not in the manifest, so nothing compiles.
     *
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function createLink(string $name, string $tokenHash, bool $canManage, CarbonInterface $expiresAt, Actor $actor): DashboardLink
    {
        $this->requireTable(new DashboardLink);
        $this->requireTable(new AuditEntry);

        // Created inside the transaction, so a retry after a deadlock inserts afresh.
        return (new DashboardLink)->getConnection()->transaction(function () use ($name, $tokenHash, $canManage, $expiresAt, $actor): DashboardLink {
            $link = DashboardLink::query()->create([
                'name' => $name,
                'token_hash' => $tokenHash,
                'can_manage' => $canManage,
                'created_by' => $actor->name,
                'expires_at' => $expiresAt,
            ])->refresh();
            $this->audit($actor, AuditAction::LinkCreate, "link:{$link->id}", null, [
                'name' => $link->name,
                'can_manage' => $link->can_manage,
                'expires_at' => $link->expires_at->toIso8601String(),
                'created_by' => $link->created_by,
            ]);

            return $link;
        }, self::ATTEMPTS);
    }

    /**
     * Ends a link, used or not, with its link.revoke entry. False when it had already
     * ended, revoked or expired, as read again, locked.
     *
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function revokeLink(DashboardLink $link, Actor $actor): bool
    {
        $this->requireTable(new DashboardLink);
        $this->requireTable(new AuditEntry);

        return $link->getConnection()->transaction(function () use ($link, $actor): bool {
            $current = DashboardLink::query()->whereKey($link->getKey())->lockForUpdate()->first();

            if (! $current instanceof DashboardLink || $current->ended()) {
                return false;
            }

            $this->endLink($current, $actor);

            return true;
        }, self::ATTEMPTS);
    }

    /**
     * Ends every link that has not ended, each with its own link.revoke entry, in one
     * transaction: all of them, or none when an entry cannot be written.
     *
     * @return int how many it ended
     *
     * @throws TableMissing|Throwable from the database, with nothing written
     */
    public function revokeLinks(Actor $actor): int
    {
        $this->requireTable(new DashboardLink);
        $this->requireTable(new AuditEntry);

        return (new DashboardLink)->getConnection()->transaction(function () use ($actor): int {
            $links = DashboardLink::query()->active()->orderBy('id')->lockForUpdate()->get();

            foreach ($links as $link) {
                $this->endLink($link, $actor);
            }

            return $links->count();
        }, self::ATTEMPTS);
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

    private function endLink(DashboardLink $link, Actor $actor): void
    {
        $revokedAt = Date::now();
        $link->revoked_at = $revokedAt;
        $link->save();
        $this->audit($actor, AuditAction::LinkRevoke, "link:{$link->id}", ['revoked_at' => null], ['revoked_at' => $revokedAt->toIso8601String()]);
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
     * An exemption's fields as an entry records them, its expiry as settings record theirs.
     *
     * @return array<string, mixed>
     */
    private function exemptionState(Exemption $exemption): array
    {
        return [
            'rule_id' => $exemption->rule_id,
            'subject' => $exemption->subject->value,
            'subject_parameter' => $exemption->subject_parameter,
            'value' => $exemption->value,
            'reason' => $exemption->reason,
            'expires_at' => $exemption->expires_at?->toIso8601String(),
        ];
    }

    /**
     * A rule's fields as an entry records them: what the API takes, enums as values.
     *
     * @return array<string, mixed>
     */
    private function ruleState(Rule $rule): array
    {
        return Arr::except($rule->attributesToArray(), ['id', 'created_at', 'updated_at']);
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
