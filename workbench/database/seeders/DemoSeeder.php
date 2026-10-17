<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Models\HourlyMetric;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Afzal\DynamicThrottle\Support\EmergencyControls;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * Demo data for trying the dashboard by hand, never for tests: rules for the demo
 * routes (WorkbenchServiceProvider), an exemption, and a week of made-up hourly
 * numbers, so the Overview's day and week charts show something at once. The
 * rules and the exemption go through EmergencyControls, as the dashboard's own
 * changes do, so the audit log has their entries and the manifest compiles.
 */
class DemoSeeder extends Seeder
{
    /** The hours of made-up numbers: a week, up to the hour before the current one. */
    private const int HOURS = 7 * 24;

    public function run(EmergencyControls $controls): void
    {
        $actor = Actor::cli();
        $rules = [
            ['name' => 'Orders API', 'pattern' => 'api/orders', 'scope' => Scope::Ip, 'max_attempts' => 10, 'window_seconds' => 60, 'priority' => 10],
            ['name' => 'Search (trying out)', 'pattern' => 'api/search', 'scope' => Scope::Ip, 'max_attempts' => 5, 'window_seconds' => 60, 'priority' => 20, 'mode' => RuleMode::DryRun],
            ['name' => 'Exports per tenant', 'pattern' => 'api/export', 'scope' => Scope::Header, 'scope_parameter' => 'X-Tenant', 'max_attempts' => 3, 'window_seconds' => 300, 'priority' => 30],
            ['name' => 'Old login rule', 'method' => 'POST', 'pattern' => 'login', 'scope' => Scope::Ip, 'max_attempts' => 5, 'window_seconds' => 60, 'priority' => 40, 'enabled' => false],
        ];

        $created = array_map(fn (array $rule): Rule => $this->rule($controls, $rule, $actor), $rules);

        // An office that is never limited, on every rule.
        $controls->createExemption(['subject' => ExemptionSubject::Ip, 'value' => '203.0.113.10', 'reason' => 'The office (demo)'], $actor);

        $this->hours($created[0]->id, 400, 0.08, 0.0);
        $this->hours($created[1]->id, 250, 0.0, 0.12);
        $this->hours($created[2]->id, 40, 0.15, 0.0);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rule(EmergencyControls $controls, array $attributes, Actor $actor): Rule
    {
        $rule = $controls->createRule([...$attributes, 'description' => 'Demo rule from the Workbench seeder.'], $actor)->subject;

        return $rule instanceof Rule ? $rule : throw new RuntimeException('The demo rule was not created.');
    }

    /**
     * A week of hourly numbers for a rule: busier by day than by night, with some noise,
     * and a share of them blocked or would-be blocked.
     */
    private function hours(int $ruleId, int $peak, float $blocked, float $wouldBlock): void
    {
        $current = Date::now('UTC')->startOfHour();
        $rows = [];

        for ($ago = self::HOURS; $ago >= 1; $ago--) {
            $hour = $current->copy()->subHours($ago);
            $daytime = (sin(($hour->hour - 6) / 24 * 2 * M_PI) + 1.2) / 2.2;
            $hits = (int) round($peak * $daytime * (0.7 + random_int(0, 60) / 100));
            $rows[] = [
                'rule_id' => $ruleId,
                'hour' => $hour->format('Y-m-d H:i:s'),
                'hits' => $hits,
                'blocks' => (int) round($hits * $blocked * (0.5 + random_int(0, 100) / 100)),
                'would_blocks' => (int) round($hits * $wouldBlock * (0.5 + random_int(0, 100) / 100)),
                'clients' => max(1, intdiv($hits, 9)),
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            HourlyMetric::query()->insert($chunk);
        }
    }
}
