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
            const answer = await api<{ feed: FeedEntry[] | null }>('GET', newest === undefined ? 'feed' : `feed?since=${newest}`);

            return answer.feed === null ? null : [...answer.feed, ...held].slice(0, FEED_SIZE);
        },
        // A store that keeps no feed will not start keeping one.
        refetchInterval: (query) => (paused || query.state.data === null ? false : 5_000),
        refetchIntervalInBackground: false,
        // Paused, the rows stay put, even when the window regains focus.
        refetchOnWindowFocus: !paused,
    });
}
