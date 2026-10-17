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
