import { ConfigCard } from '@/pages/settings/config-card';

/** Settings (SPEC-031): what servers run with, read-only for everyone (decision 1). */
export function SettingsPage() {
    return (
        <section className="grid gap-6">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">Settings</h1>
                <p className="mt-1 text-sm text-muted-foreground">What the servers run with. Nothing here changes anything.</p>
            </div>
            <ConfigCard />
        </section>
    );
}

export default SettingsPage;
