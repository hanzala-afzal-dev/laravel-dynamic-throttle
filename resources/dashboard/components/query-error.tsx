import { ApiError } from '@/lib/api';

/** Why a query failed, as the API client words it (SPEC-020). */
export function QueryError({ error }: { error: unknown }) {
    const message = error instanceof ApiError || error instanceof Error ? error.message : 'The request failed.';

    return <p className="text-sm text-destructive">{message}</p>;
}
