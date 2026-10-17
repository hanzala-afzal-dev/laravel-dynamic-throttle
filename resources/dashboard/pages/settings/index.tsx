import { CliCard } from '@/pages/settings/cli-card';
import { ConfigCard } from '@/pages/settings/config-card';
import { SettingsHealthCard } from '@/pages/settings/health-card';

/** Settings (SPEC-031): the health checks, what servers run with, and the CLI, read-only for everyone (decision 1). */
export function SettingsPage() {
    return (
        <section className="grid gap-6">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">Settings</h1>
                <p className="mt-1 text-sm text-muted-foreground">What the servers run with. Nothing here changes anything.</p>
            </div>
            <SettingsHealthCard />
            <div className="grid items-start gap-6 xl:grid-cols-[2fr_1fr]">
                <ConfigCard />
                <CliCard />
            </div>
        </section>
    );
}

export default SettingsPage;
