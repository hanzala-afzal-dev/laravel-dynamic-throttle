<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Models;

use Afzal\DynamicThrottle\Models\Concerns\StoresTimesInUtc;
use Afzal\DynamicThrottle\Models\Concerns\UsesPackageConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The audit log, read (SPEC-019 decision 4). It refuses to write, so AuditEntry,
 * which EmergencyControls alone writes with each change, stays the log's only
 * writer (SPEC-013 AC-23).
 *
 * @property int $id
 * @property string $actor
 * @property string $action as written, so an action this version does not know still reads
 * @property string $target
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
final class AuditLogEntry extends Model
{
    use StoresTimesInUtc;
    use UsesPackageConnection;

    public const UPDATED_AT = null;

    protected $table = 'dynamic_throttle_audit_log';

    /** No guard of its own: every write is refused below, with the reason. */
    protected $guarded = [];

    protected static function booted(): void
    {
        self::creating(fn (): never => throw new LogicException('The audit log is read here: EmergencyControls writes it, with each change.'));
        self::updating(fn (): never => throw new LogicException('The audit log is append-only: an entry is never changed.'));
        self::deleting(fn (): never => throw new LogicException('The audit log is append-only: an entry is never deleted.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
