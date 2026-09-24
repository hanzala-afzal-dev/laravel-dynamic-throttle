<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Models;

use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A rate-limit rule, edited by admins (SPEC-005, ADR-0007). Requests never read
 * this model: rules reach them through the compiled manifest (SPEC-006).
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property RequestMethod $method
 * @property string $pattern
 * @property Scope $scope
 * @property string|null $scope_parameter
 * @property int $max_attempts
 * @property int $window_seconds
 * @property int $priority
 * @property RuleMode $mode
 * @property FailurePolicy $on_failure
 * @property MissingIdentity $on_missing_identity
 * @property array<array-key, mixed>|null $response_override
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Rule extends Model
{
    protected $table = 'dynamic_throttle_rules';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
        'method',
        'pattern',
        'scope',
        'scope_parameter',
        'max_attempts',
        'window_seconds',
        'priority',
        'mode',
        'on_failure',
        'on_missing_identity',
        'response_override',
        'enabled',
    ];

    /**
     * The migration's defaults, so a new model has them before it is saved.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'method' => '*',
        'priority' => 100,
        'mode' => 'enforce',
        'on_failure' => 'open',
        'on_missing_identity' => 'ip',
        'enabled' => true,
    ];

    /**
     * The connection from config('dynamic-throttle.database.connection'), or the app's default.
     */
    public function getConnectionName(): ?string
    {
        $connection = config('dynamic-throttle.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => RequestMethod::class,
            'scope' => Scope::class,
            'mode' => RuleMode::class,
            'on_failure' => FailurePolicy::class,
            'on_missing_identity' => MissingIdentity::class,
            'max_attempts' => 'integer',
            'window_seconds' => 'integer',
            'priority' => 'integer',
            'enabled' => 'boolean',
            'response_override' => 'array',
        ];
    }
}
