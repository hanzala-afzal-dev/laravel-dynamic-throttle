import { useConfirmedChange } from '@/lib/confirmed-change';

/** A change of bypass or the scale factor, as the API takes it (SPEC-017). */
export type SettingChange = { path: 'settings/bypass' | 'settings/scale'; body: Record<string, unknown> };

/** Changes bypass or the scale factor, for the Overview's controls and the banner (SPEC-021). */
export function useSettingChange() {
    const change = useConfirmedChange([['settings']]);

    return { ...change, send: ({ path, body }: SettingChange) => change.send({ method: 'PUT', path, body }) };
}
