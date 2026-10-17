import { FeedCard } from '@/pages/activity/feed-card';

/** Activity (SPEC-024): who is blocked right now, from where, and on which route. */
export function ActivityPage() {
    return (
        <section className="grid gap-6">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">Activity</h1>
                <p className="mt-1 text-sm text-muted-foreground">Who the rules block right now, from where, and on which route.</p>
            </div>
            <FeedCard />
        </section>
    );
}

export default ActivityPage;
