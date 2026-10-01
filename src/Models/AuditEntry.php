<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One change, in the append-only audit log (ADR-0015, SPEC-013). Stub until SPEC-013's implementation.
 */
final class AuditEntry extends Model
{
    protected $table = 'dynamic_throttle_audit_log';
}
