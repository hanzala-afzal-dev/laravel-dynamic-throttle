import { useId } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

const RUNBOOK = 'https://github.com/hanzala-afzal-dev/laravel-dynamic-throttle/blob/main/docs/runbook.md';

const COMMANDS: { does: string; commands: string[] }[] = [
    { does: 'Lift every limit for an hour, or end it', commands: ['php artisan dynamic-throttle:bypass on --for=1h', 'php artisan dynamic-throttle:bypass off'] },
    { does: 'Halve every limit for an hour, or end it', commands: ['php artisan dynamic-throttle:scale 0.5 --for=1h', 'php artisan dynamic-throttle:scale off'] },
    { does: 'Find a rule\'s id, then switch it off or on', commands: ['php artisan dynamic-throttle:list', 'php artisan dynamic-throttle:disable-rule {id}', 'php artisan dynamic-throttle:enable-rule {id}'] },
    { does: 'See what servers apply, and run the health checks', commands: ['php artisan dynamic-throttle:status', 'php artisan dynamic-throttle:health'] },
];

/** The Artisan commands that do what the dashboard does, for when it is down (SPEC-031 AC-5). */
export function CliCard() {
    const id = useId();

    return (
        <Card role="region" aria-labelledby={id}>
            <CardHeader>
                <CardTitle>
                    <h2 id={id}>When the dashboard is down</h2>
                </CardTitle>
                <CardDescription>Run these on a server of the app. They change what every server applies, as the dashboard does.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4">
                {COMMANDS.map((group) => (
                    <div key={group.does} className="grid gap-1.5">
                        <p className="text-sm font-medium">{group.does}</p>
                        {group.commands.map((command) => (
                            <code key={command} className="block rounded-md bg-muted px-3 py-2 font-mono text-xs break-all select-all">
                                {command}
                            </code>
                        ))}
                    </div>
                ))}
                <p className="text-sm">
                    <a href={RUNBOOK} target="_blank" rel="noreferrer" className="underline underline-offset-4">
                        The runbook
                    </a>{' '}
                    says what to do when Redis is down, the manifest was evicted, a bad rule blocks your API, or an emergency change was left on.
                </p>
            </CardContent>
        </Card>
    );
}
