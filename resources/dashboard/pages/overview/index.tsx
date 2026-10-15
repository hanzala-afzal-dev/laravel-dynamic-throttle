import { HealthCard } from '@/pages/overview/health-card';
import { RulesCard } from '@/pages/overview/rules-card';

/** The dashboard's first page (SPEC-021): what limiting does now, and the emergency controls. */
export default function OverviewPage() {
    return (
        <section className="grid gap-6">
            <h1 className="text-2xl font-semibold tracking-tight">Overview</h1>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <HealthCard />
                <RulesCard />
            </div>
        </section>
    );
}
