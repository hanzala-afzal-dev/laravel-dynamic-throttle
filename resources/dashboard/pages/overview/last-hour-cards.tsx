import { QueryError } from '@/components/query-error';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatCount, formatShare } from '@/lib/format';
import { totals } from '@/lib/metrics';
import { useMetrics } from '@/lib/queries';

/** The last hour's blocks and would-blocks, whatever period the chart shows (SPEC-021 AC-5). */
export function LastHourCards() {
    const metrics = useMetrics('hour');
    const sums = metrics.data === undefined ? undefined : totals(metrics.data);

    return (
        <>
            <StatCard
                title="Blocks in the last hour"
                error={metrics.error}
                value={sums?.blocks}
                detail={sums === undefined ? undefined : sums.hits === 0 ? 'No rule checked a request.' : `${formatShare(sums.blocks, sums.hits)} of ${formatCount(sums.hits)} checks`}
            />
            <StatCard title="Would-blocks in the last hour" error={metrics.error} value={sums?.wouldBlocks} detail={sums === undefined ? undefined : 'What the dry-run rules would have blocked'} />
        </>
    );
}

function StatCard({ title, error, value, detail }: { title: string; error: unknown; value: number | undefined; detail: string | undefined }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    <h2>{title}</h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-1">
                {error !== null && <QueryError error={error} />}
                {value !== undefined && <p className="text-2xl font-semibold tabular-nums">{formatCount(value)}</p>}
                {detail !== undefined && <p className="text-sm text-muted-foreground">{detail}</p>}
            </CardContent>
        </Card>
    );
}
