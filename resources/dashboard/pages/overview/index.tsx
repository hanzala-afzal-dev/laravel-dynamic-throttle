import { useState } from 'react';
import type { Period } from '@/lib/metrics';
import { EmergencyControls } from '@/pages/overview/emergency-controls';
import { HealthCard } from '@/pages/overview/health-card';
import { LastHourCards } from '@/pages/overview/last-hour-cards';
import { RulesCard } from '@/pages/overview/rules-card';
import { TopRulesCard } from '@/pages/overview/top-rules-card';
import { TrafficCard } from '@/pages/overview/traffic-card';

/** The dashboard's first page (SPEC-021): what limiting does now, and the emergency controls. */
export default function OverviewPage() {
    // The chart's period, which the top rules follow.
    const [period, setPeriod] = useState<Period>('hour');

    return (
        <section className="grid gap-6">
            <h1 className="text-2xl font-semibold tracking-tight">Overview</h1>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <HealthCard />
                <RulesCard />
                <LastHourCards />
            </div>
            <div className="grid gap-4 xl:grid-cols-3">
                <TrafficCard period={period} onPeriodChange={setPeriod} className="xl:col-span-2" />
                <EmergencyControls />
            </div>
            <TopRulesCard period={period} />
        </section>
    );
}
