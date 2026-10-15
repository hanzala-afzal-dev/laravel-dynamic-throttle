import { useMutation, useQueryClient } from '@tanstack/react-query';
import { TriangleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { api, ApiError } from '@/lib/api';
import { useCanManage, useSettings } from '@/lib/queries';
import { Time } from '@/lib/time';

/**
 * On every page while bypass or a scale factor is on: what it does, until when, and
 * for a manager a button that turns it off (SPEC-020). Turning off needs no
 * confirmation (SPEC-018).
 */
export function EmergencyBanner() {
    const settings = useSettings().data;
    const canManage = useCanManage();
    const client = useQueryClient();
    const turnOff = useMutation({
        mutationFn: ({ path, body }: { path: string; body: Record<string, string> }) => api('PUT', path, body),
        onSuccess: () => client.invalidateQueries({ queryKey: ['settings'] }),
    });

    if (settings === undefined || (!settings.bypass.on && settings.scale.factor === null)) {
        return null;
    }

    return (
        <Alert variant="warning" className="mb-6">
            <TriangleAlert />
            <AlertTitle>An emergency setting is on</AlertTitle>
            <AlertDescription className="text-foreground">
                {settings.bypass.on && (
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <p>
                            Bypass is on: no rule limits any request <Until value={settings.bypass.until} />.
                        </p>
                        {canManage && (
                            <Button size="sm" variant="outline" disabled={turnOff.isPending} onClick={() => turnOff.mutate({ path: 'settings/bypass', body: { state: 'off' } })}>
                                Turn off bypass
                            </Button>
                        )}
                    </div>
                )}
                {settings.scale.factor !== null && (
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <p>
                            Every limit is multiplied by {settings.scale.factor}
                            <Until value={settings.scale.until} comma />.
                        </p>
                        {canManage && (
                            <Button size="sm" variant="outline" disabled={turnOff.isPending} onClick={() => turnOff.mutate({ path: 'settings/scale', body: { factor: 'off' } })}>
                                Turn off scaling
                            </Button>
                        )}
                    </div>
                )}
                {turnOff.error instanceof ApiError && <p className="text-destructive">{turnOff.error.message}</p>}
            </AlertDescription>
        </Alert>
    );
}

function Until({ value, comma = false }: { value: string | null; comma?: boolean }) {
    if (value === null) {
        return <>, with no end time</>;
    }

    return (
        <>
            {comma ? ' ' : ''}until <Time value={value} />
        </>
    );
}
