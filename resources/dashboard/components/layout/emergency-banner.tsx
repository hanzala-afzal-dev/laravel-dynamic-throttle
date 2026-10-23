import { TriangleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useCanManage, useSettings } from '@/lib/queries';
import { useSettingChange } from '@/lib/setting-change';
import { Time } from '@/lib/time';

/**
 * On every page while bypass or a scale factor is on: what it does, until when, and
 * for a manager a button that turns it off (SPEC-020). Turning off needs no
 * confirmation (SPEC-018); a failure or a failed compile shows here (SPEC-021).
 */
export function EmergencyBanner() {
    const settings = useSettings().data;
    const canManage = useCanManage();
    const turnOff = useSettingChange();

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
                            Bypass is on: no rule limits any request
                            <Until value={settings.bypass.until} />.
                        </p>
                        {canManage && (
                            <Button size="sm" variant="outline" disabled={turnOff.pending} onClick={() => turnOff.send({ path: 'settings/bypass', body: { state: 'off' } })}>
                                Turn off bypass
                            </Button>
                        )}
                    </div>
                )}
                {settings.scale.factor !== null && (
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <p>
                            Every limit is multiplied by {settings.scale.factor}
                            <Until value={settings.scale.until} />.
                        </p>
                        {canManage && (
                            <Button size="sm" variant="outline" disabled={turnOff.pending} onClick={() => turnOff.send({ path: 'settings/scale', body: { factor: 'off' } })}>
                                Turn off scaling
                            </Button>
                        )}
                    </div>
                )}
                {turnOff.error !== null && <p className="text-destructive">{turnOff.error.message}</p>}
                {turnOff.warning !== null && <p className="font-medium">{turnOff.warning}</p>}
            </AlertDescription>
        </Alert>
    );
}

/** The end of the sentence before it: ", with no end time" or " until <time>", with its own space. */
function Until({ value }: { value: string | null }) {
    if (value === null) {
        return <>, with no end time</>;
    }

    return (
        <>
            {' '}until <Time value={value} />
        </>
    );
}
