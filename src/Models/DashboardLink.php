<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Models;

use Afzal\DynamicThrottle\Models\Concerns\StoresTimesInUtc;
use Afzal\DynamicThrottle\Models\Concerns\UsesPackageConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * A link that opens the dashboard (SPEC-034, ADR-0031). It stores a hash of its
 * token, never the token. It has ended once it is revoked or its end time has passed.
 *
 * @property int $id
 * @property string $name who it is for, such as "alice, incident 42"
 * @property string $token_hash the SHA-256 of the token, in hex
 * @property bool $can_manage
 * @property string $created_by cli:<system user>
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 *
 * @internal
 */
final class DashboardLink extends Model
{
    use StoresTimesInUtc;
    use UsesPackageConnection;

    /** A link is never updated but to use or end it, which have their own columns. */
    public const UPDATED_AT = null;

    protected $table = 'dynamic_throttle_links';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'token_hash',
        'can_manage',
        'created_by',
        'expires_at',
    ];

    /** The hash never leaves the server, not even in an array of the model. */
    protected $hidden = ['token_hash'];

    public function ended(): bool
    {
        return $this->revoked_at !== null || $this->expires_at->lessThanOrEqualTo(Date::now());
    }

    /**
     * The links that have not ended.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', $this->fromDateTime(Date::now()));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'can_manage' => 'boolean',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
