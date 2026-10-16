import { useId, useState } from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { QueryError } from '@/components/query-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { type Settings, useCanManage, useSettings } from '@/lib/queries';
import { useSettingChange } from '@/lib/setting-change';
import { Time } from '@/lib/time';

/** How long a setting lasts, as the CLI's --for takes it, or no end time (SPEC-021 decision 7). */
const DURATIONS = [
    { value: '15m', label: '15 minutes' },
    { value: '1h', label: '1 hour' },
    { value: '4h', label: '4 hours' },
    { value: '1d', label: '1 day' },
    { value: 'forever', label: 'No end time' },
] as const;

const DEFAULT_DURATION = '1h';

/** A setting's end time, as the API takes it. */
function ending(duration: string): Record<string, unknown> {
    return duration === 'forever' ? { forever: true } : { for: duration };
}

/** Bypass and the scale factor: their state, and for a manager the controls (SPEC-021 AC-9 to AC-12). */
export function EmergencyControls({ className }: { className?: string }) {
    const settings = useSettings();
    const canManage = useCanManage();

    return (
        <Card className={className}>
            <CardHeader>
                <CardTitle>
                    <h2>Emergency controls</h2>
                </CardTitle>
                <CardDescription>For every rule at once. Each change goes to the audit log.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-6">
                {settings.isError && <QueryError error={settings.error} />}
                {settings.data !== undefined && (
                    <>
                        <Bypass state={settings.data.bypass} canManage={canManage} />
                        <Scaling state={settings.data.scale} canManage={canManage} />
                    </>
                )}
            </CardContent>
        </Card>
    );
}

function Bypass({ state, canManage }: { state: Settings['bypass']; canManage: boolean }) {
    const [duration, setDuration] = useState<string>(DEFAULT_DURATION);
    const change = useSettingChange();
    const id = useId();

    return (
        <section className="grid gap-3">
            <h3 className="font-medium">Bypass</h3>
            {state.on ? (
                <p className="text-sm">
                    Bypass is on{state.until === null ? ', with no end time' : <> until <Time value={state.until} /></>}.
                </p>
            ) : (
                <p className="text-sm text-muted-foreground">Bypass is off.</p>
            )}
            {canManage &&
                (state.on ? (
                    <Button variant="outline" className="w-fit" disabled={change.pending} onClick={() => change.send({ path: 'settings/bypass', body: { state: 'off' } })}>
                        Turn off bypass
                    </Button>
                ) : (
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${id}-for`}>Bypass for</Label>
                            <Durations id={`${id}-for`} value={duration} onChange={setDuration} />
                        </div>
                        <Button variant="destructive" disabled={change.pending} onClick={() => change.send({ path: 'settings/bypass', body: { state: 'on', ...ending(duration) } })}>
                            Turn on bypass
                        </Button>
                    </div>
                ))}
            <Outcome change={change} />
            <Confirmation change={change} />
        </section>
    );
}

function Scaling({ state, canManage }: { state: Settings['scale']; canManage: boolean }) {
    const [factor, setFactor] = useState('');
    const [duration, setDuration] = useState<string>(DEFAULT_DURATION);
    const change = useSettingChange();
    const id = useId();
    const factorError = change.fields.factor?.[0];

    return (
        <section className="grid gap-3">
            <h3 className="font-medium">Scaling</h3>
            {state.factor === null ? (
                <p className="text-sm text-muted-foreground">Limits are not scaled.</p>
            ) : (
                <p className="text-sm">
                    Every limit is multiplied by {state.factor}
                    {state.until === null ? ', with no end time' : <> until <Time value={state.until} /></>}.
                </p>
            )}
            {canManage &&
                (state.factor !== null ? (
                    <Button variant="outline" className="w-fit" disabled={change.pending} onClick={() => change.send({ path: 'settings/scale', body: { factor: 'off' } })}>
                        Turn off scaling
                    </Button>
                ) : (
                    <form
                        className="grid gap-1.5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            change.send({ path: 'settings/scale', body: { factor: factor.trim(), ...ending(duration) } });
                        }}
                    >
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${id}-factor`}>Factor</Label>
                                <Input
                                    id={`${id}-factor`}
                                    className="w-36"
                                    inputMode="decimal"
                                    placeholder="such as 0.5 or 2"
                                    value={factor}
                                    onChange={(event) => setFactor(event.target.value)}
                                    aria-invalid={factorError !== undefined}
                                    aria-describedby={factorError === undefined ? undefined : `${id}-factor-error`}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${id}-for`}>Scale for</Label>
                                <Durations id={`${id}-for`} value={duration} onChange={setDuration} />
                            </div>
                            <Button type="submit" disabled={change.pending || factor.trim() === ''}>
                                Apply
                            </Button>
                        </div>
                        {factorError !== undefined && (
                            <p id={`${id}-factor-error`} className="text-sm text-destructive">
                                {factorError}
                            </p>
                        )}
                    </form>
                ))}
            {factorError === undefined && <Outcome change={change} />}
            <Confirmation change={change} />
        </section>
    );
}

function Durations({ id, value, onChange }: { id: string; value: string; onChange: (value: string) => void }) {
    return (
        <NativeSelect id={id} value={value} onChange={(event) => onChange(event.target.value)}>
            {DURATIONS.map((duration) => (
                <option key={duration.value} value={duration.value}>
                    {duration.label}
                </option>
            ))}
        </NativeSelect>
    );
}

type Changing = ReturnType<typeof useSettingChange>;

/** Why the change failed, or that it was saved but did not compile. */
function Outcome({ change }: { change: Changing }) {
    if (change.error !== null) {
        return <p className="text-sm text-destructive">{change.error.message}</p>;
    }

    return change.warning === null ? null : <p className="text-sm font-medium text-amber-700 dark:text-warning">{change.warning}</p>;
}

function Confirmation({ change }: { change: Changing }) {
    const confirming = change.confirming;

    return (
        <AlertDialog open={change.asking} onOpenChange={(open) => !open && change.close()}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Confirm the change</AlertDialogTitle>
                    <AlertDialogDescription>
                        {confirming?.message} The end time counts from when you confirm.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction onClick={change.confirm}>Confirm</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
