<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Models\Rule;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs the package's own migration, as an app does after publishing it (SPEC-005).
 */
final class RuleSchema
{
    public const string MIGRATIONS = __DIR__.'/../../database/migrations';

    public static function migrate(): void
    {
        Artisan::call('migrate', ['--path' => self::MIGRATIONS, '--realpath' => true]);
    }

    public static function rollback(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATIONS, '--realpath' => true]);
    }

    /**
     * The fields a rule needs, with no defaults (SPEC-005 AC-13).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function attributes(array $overrides = []): array
    {
        return [
            'name' => 'API',
            'pattern' => 'api/*',
            'scope' => Scope::Ip,
            'max_attempts' => 60,
            'window_seconds' => 60,
            ...$overrides,
        ];
    }

    /**
     * Saves a rule with no default value left, and reads it back (SPEC-005 AC-14).
     *
     * @return array{Rule, array<string, mixed>}
     */
    public static function roundTrip(): array
    {
        $override = ['message' => 'Slow down', 'retry' => ['after' => 30]];
        $id = Rule::create(self::attributes([
            'description' => 'Keeps scrapers off the API.',
            'method' => RequestMethod::Post,
            'scope' => Scope::Header,
            'scope_parameter' => 'X-Api-Key',
            'priority' => 5,
            'mode' => RuleMode::DryRun,
            'on_failure' => FailurePolicy::Closed,
            'on_missing_identity' => MissingIdentity::Skip,
            'response_override' => $override,
            'enabled' => false,
        ]))->getKey();

        return [Rule::query()->findOrFail($id), $override];
    }
}
