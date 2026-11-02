import { useState } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { dashboardBase } from '@/lib/api';
import { NO_TOKEN, openLink } from '@/lib/link';

type Props = {
    /** The token main.tsx took from the address, or null when it had none. */
    token: string | null;
    /** Where the page goes once the link is used: a full load, since the session's CSRF token is new. */
    onOpened?: (url: string) => void;
};

/**
 * The link's page (SPEC-034 AC-21 and AC-22), outside the dashboard's frame, which
 * would ask who the user is before the link has made a session. It sends nothing
 * until its holder presses "Continue", so a chat app's preview, or a mail scanner
 * that runs scripts, never uses the link.
 */
export default function LinkPage({ token, onOpened = (url) => window.location.replace(url) }: Props) {
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function open(used: string): Promise<void> {
        setSending(true);
        setError(null);

        try {
            await openLink(used);
            onOpened(dashboardBase() === '' ? '/' : dashboardBase());
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : String(failure));
            setSending(false);
        }
    }

    return (
        <main className="flex min-h-screen items-center justify-center p-4">
            <section className="w-full max-w-md space-y-4">
                <h1 className="text-2xl font-semibold tracking-tight">Open the Dynamic Throttle dashboard</h1>
                {token === null ? (
                    <Alert variant="destructive">
                        <AlertDescription>{NO_TOKEN}</AlertDescription>
                    </Alert>
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground">This link opens the dashboard once, in this browser.</p>
                        {error !== null && (
                            <Alert variant="destructive">
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}
                        <Button onClick={() => void open(token)} disabled={sending}>
                            {sending ? 'Opening…' : 'Continue'}
                        </Button>
                    </>
                )}
            </section>
        </main>
    );
}
