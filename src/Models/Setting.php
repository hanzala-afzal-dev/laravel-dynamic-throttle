<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Models;

use Afzal\DynamicThrottle\Enums\SettingName;
use Afzal\DynamicThrottle\Models\Concerns\StoresTimesInUtc;
use Afzal\DynamicThrottle\Models\Concerns\UsesPackageConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A runtime setting that is on (ADR-0011, SPEC-013): bypass_all, or the scale
 * factor. Turning a setting off deletes its row. Requests never read this
 * model: settings reach them through the compiled manifest, until their end
 * time.
 *
 * @property int $id
 * @property SettingName $name
 * @property string $value "1" for bypass_all, the factor for scale_factor, such as "1.5"
 * @property Carbon|null $expires_at null for no end time
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Setting extends Model
{
    use StoresTimesInUtc;
    use UsesPackageConnection;

    protected $table = 'dynamic_throttle_settings';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'value',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => SettingName::class,
            'expires_at' => 'datetime',
        ];
    }
}
