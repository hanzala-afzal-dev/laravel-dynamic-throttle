<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Models;

use Afzal\DynamicThrottle\Enums\AuditAction;
use Afzal\DynamicThrottle\Models\Concerns\UsesPackageConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One change, in the append-only audit log (ADR-0015 point 3, SPEC-013): who
 * made it, what it changed, and the state before and after. EmergencyControls
 * writes it with the change, in one transaction. The model refuses to change or
 * delete an entry.
 *
 * @property int $id
 * @property string $actor cli:<system user> for the CLI
 * @property AuditAction $action
 * @property string $target such as setting:bypass_all or rule:12
 * @property array<string, mixed>|null $before null when the target did not exist
 * @property array<string, mixed>|null $after null when the target no longer exists
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
final class AuditEntry extends Model
{
    use UsesPackageConnection;

    /** Entries are never updated. */
    public const UPDATED_AT = null;

    protected $table = 'dynamic_throttle_audit_log';

    /** @var list<string> */
    protected $fillable = [
        'actor',
        'action',
        'target',
        'before',
        'after',
        'ip',
        'user_agent',
    ];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('The audit log is append-only: an entry is never changed.'));
        self::deleting(fn (): never => throw new LogicException('The audit log is append-only: an entry is never deleted.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
