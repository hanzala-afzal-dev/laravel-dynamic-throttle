import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { api, ApiError } from '@/lib/api';

/** A change of bypass or the scale factor, as the API takes it (SPEC-017). */
export type SettingChange = { path: 'settings/bypass' | 'settings/scale'; body: Record<string, unknown> };

/** What a change answers about the compile that followed it (SPEC-017 decision 12). */
type Answer = { manifest?: { compiled: boolean; error?: string } | null };

/** A 409 that asks to confirm a change sent without "confirm" (SPEC-018). */
function asksToConfirm(error: unknown, change: SettingChange | undefined): error is ApiError {
    return error instanceof ApiError && error.needsConfirmation && change?.body.confirm !== true;
}

/**
 * Changes bypass or the scale factor, for the Overview's controls and the banner
 * (SPEC-021). A change goes without "confirm"; when the API answers 409, it waits,
 * with the API's own message, for confirm(), which sends that change again with
 * "confirm": true, once (decision 8). It reports a change the server could not be
 * reached for, and one that was saved but did not compile.
 */
export function useSettingChange() {
    const client = useQueryClient();
    // The change waiting for confirmation, read and cleared at once, so two quick clicks send it once.
    const waiting = useRef<SettingChange | null>(null);
    // Kept after the dialog closes, so its message stays while it fades out.
    const [confirming, setConfirming] = useState<(SettingChange & { message: string }) | null>(null);
    const [asking, setAsking] = useState(false);
    const mutation = useMutation({
        mutationFn: ({ path, body }: SettingChange) => api<Answer>('PUT', path, body),
        onSuccess: () => client.invalidateQueries({ queryKey: ['settings'] }),
        onError: (error, change) => {
            if (asksToConfirm(error, change)) {
                waiting.current = change;
                setConfirming({ ...change, message: error.message });
                setAsking(true);
            }
        },
    });
    const failed = mutation.error;
    const error = failed === null || asksToConfirm(failed, mutation.variables) ? null : failed;
    const manifest = mutation.data?.manifest;

    return {
        send: (change: SettingChange) => mutation.mutate(change),
        pending: mutation.isPending,
        /** Why the change failed, but not the 409 that asked to confirm it. */
        error,
        /** A 422's messages, under each field. */
        fields: error instanceof ApiError ? error.fields : {},
        /** The change was saved, but servers keep the previous manifest (SPEC-021 AC-15). */
        warning: manifest?.compiled === false ? `Saved, but servers keep the previous manifest until a compile succeeds: ${manifest.error ?? 'the compile failed.'}` : null,
        confirming,
        asking,
        confirm: () => {
            const change = waiting.current;
            waiting.current = null;
            setAsking(false);

            if (change !== null) {
                mutation.mutate({ path: change.path, body: { ...change.body, confirm: true } });
            }
        },
        close: () => {
            waiting.current = null;
            setAsking(false);
        },
    };
}
