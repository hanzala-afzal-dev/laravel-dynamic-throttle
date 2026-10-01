<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A runtime setting that is on (ADR-0011, SPEC-013). Stub until SPEC-013's implementation.
 */
final class Setting extends Model
{
    protected $table = 'dynamic_throttle_settings';
}
