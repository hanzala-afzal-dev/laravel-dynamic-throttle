import { type QueryKey, useMutation, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { api, ApiError, type Method } from '@/lib/api';

/** A change the API makes, as it takes it (SPEC-017). */
export type Change = { method: Exclude<Method, 'GET'>; path: string; body: Record<string, unknown> };

/** What a change answers about the compile that followed it (SPEC-017 decision 12). */
type Answer = { manifest?: { compiled: boolean; error?: string } | null };

/** A 409 that asks to confirm a change sent without "confirm" (SPEC-018). */
function asksToConfirm(error: unknown, change: Change | undefined): error is ApiError {
    return error instanceof ApiError && error.needsConfirmation && change?.body.confirm !== true;
}

/**
 * Makes a change, then refreshes the queries it touched (SPEC-021, SPEC-022). A
 * change goes without "confirm"; when the API answers 409, it waits, with the API's
 * own message, for confirm(), which sends that change again with "confirm": true,
 * once (SPEC-021 decision 8). It reports a change the server could not be reached
 * for, and one that was saved but did not compile.
 */
export function useConfirmedChange(invalidate: QueryKey[]) {
    const client = useQueryClient();
    // The change waiting for confirmation, read and cleared at once, so two quick clicks send it once.
    const waiting = useRef<Change | null>(null);
    // Kept after the dialog closes, so its message stays while it fades out.
    const [confirming, setConfirming] = useState<(Change & { message: string }) | null>(null);
    const [asking, setAsking] = useState(false);
    const mutation = useMutation({
        mutationFn: ({ method, path, body }: Change) => api<Answer>(method, path, body),
        onSuccess: () => Promise.all(invalidate.map((queryKey) => client.invalidateQueries({ queryKey }))),
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
        send: (change: Change) => mutation.mutate(change),
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
                mutation.mutate({ ...change, body: { ...change.body, confirm: true } });
            }
        },
        close: () => {
            waiting.current = null;
            setAsking(false);
        },
    };
}

export type ConfirmedChange = ReturnType<typeof useConfirmedChange>;
