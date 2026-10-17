import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

/** An entry of the live feed, as GET api/feed gives it (SPEC-030). */
export type FeedEntry = {
    time: number;
    rule: number;
    rule_name: string | null;
    outcome: 'blocked' | 'would_block';
    method: string;
    route: string | null;
    ip: string | null;
    user: string | null;
    identity: string;
};

/** The page holds at most this many entries, as the API answers at most this many (SPEC-024 decision 1). */
export const FEED_SIZE = 500;

const FEED_KEY = ['feed'] as const;

/**
 * Each poll asks again for the last 2 seconds it saw: another worker or server can
 * write a block a moment after a later one (SPEC-024 decision 1).
 */
export const FEED_OVERLAP_MS = 2_000;

/**
 * The live feed, newest first: null on a store that keeps none. Every 5 seconds while
 * the page is visible and not paused, it asks only for what came after the newest
 * entry it holds, and adds that on top (SPEC-024 AC-2).
 */
export function useLiveFeed(paused: boolean) {
    const client = useQueryClient();

    return useQuery({
        queryKey: FEED_KEY,
        queryFn: async () => {
            const held = client.getQueryData<FeedEntry[] | null>(FEED_KEY) ?? [];
            const newest = held[0]?.time;
            const since = newest === undefined ? null : newest - FEED_OVERLAP_MS;
            const answer = await api<{ feed: FeedEntry[] | null }>('GET', since === null ? 'feed' : `feed?since=${since}`);

            return answer.feed === null ? null : merged(held, answer.feed, since ?? 0);
        },
        // A store that keeps no feed will not start keeping one.
        refetchInterval: (query) => (paused || query.state.data === null ? false : 5_000),
        refetchIntervalInBackground: false,
        // Paused, the rows stay put, even when the window regains focus.
        refetchOnWindowFocus: !paused,
    });
}

/** An entry's fields, which tell two entries apart. */
function keyOf(entry: FeedEntry): string {
    return JSON.stringify([entry.time, entry.rule, entry.outcome, entry.method, entry.route, entry.ip, entry.user, entry.identity]);
}

/**
 * The entries held, with those of the answer they do not hold already, newest first, at
 * most FEED_SIZE. The answer repeats what the page holds after `since`: each entry the page
 * holds there cancels one equal entry of the answer, so a second identical block is still added.
 */
function merged(held: FeedEntry[], answer: FeedEntry[], since: number): FeedEntry[] {
    const counts = new Map<string, number>();

    for (const entry of held) {
        if (entry.time > since) {
            const key = keyOf(entry);
            counts.set(key, (counts.get(key) ?? 0) + 1);
        }
    }

    const added = answer.filter((entry) => {
        const key = keyOf(entry);
        const count = counts.get(key) ?? 0;

        if (count === 0) {
            return true;
        }

        counts.set(key, count - 1);

        return false;
    });

    // A stable sort keeps the API's order within a millisecond.
    return [...added, ...held].sort((a, b) => b.time - a.time).slice(0, FEED_SIZE);
}
