import { Bar, BarChart, CartesianGrid, type TooltipContentProps, XAxis, YAxis } from 'recharts';
import { QueryError } from '@/components/query-error';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { type ChartConfig, ChartContainer, ChartLegend, ChartTooltip } from '@/components/ui/chart';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatCount, formatShare } from '@/lib/format';
import { isPeriod, type Period, type Point, series, totals } from '@/lib/metrics';
import { useMetrics } from '@/lib/queries';
import { formatTime } from '@/lib/time';
import { cn } from '@/lib/utils';

const PERIODS: { value: Period; label: string }[] = [
    { value: 'hour', label: 'Hour' },
    { value: 'day', label: 'Day' },
    { value: 'week', label: 'Week' },
];

/** Blocks and would-blocks never overlap, so they stack (SPEC-021 decision 3). */
const CHART: ChartConfig = {
    blocks: { label: 'Blocks', color: 'var(--destructive)' },
    wouldBlocks: { label: 'Would-blocks', color: 'var(--warning)' },
};

/** The blocks and would-blocks over the last hour, day or week (SPEC-021 AC-6). */
export function TrafficCard({ period, onPeriodChange, className }: { period: Period; onPeriodChange: (period: Period) => void; className?: string }) {
    return (
        <Card className={className}>
            <Tabs value={period} onValueChange={(value) => isPeriod(value) && onPeriodChange(value)} className="gap-6">
                <CardHeader className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle>
                        <h2>Traffic</h2>
                    </CardTitle>
                    <TabsList aria-label="Period">
                        {PERIODS.map(({ value, label }) => (
                            <TabsTrigger key={value} value={value}>
                                {label}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </CardHeader>
                <CardContent>
                    {PERIODS.map(({ value }) => (
                        <TabsContent key={value} value={value}>
                            <Traffic period={value} />
                        </TabsContent>
                    ))}
                </CardContent>
            </Tabs>
        </Card>
    );
}

function Traffic({ period }: { period: Period }) {
    const metrics = useMetrics(period);

    if (metrics.isError) {
        return <QueryError error={metrics.error} />;
    }

    if (metrics.data === undefined) {
        return <Skeleton className="h-64 w-full" />;
    }

    const sums = totals(metrics.data);

    if (sums.hits === 0) {
        return <p className="text-sm text-muted-foreground">No rule checked a request in the last {period}.</p>;
    }

    return (
        <div className="grid gap-4">
            <p className="text-sm">
                {formatCount(sums.hits)} checks, {formatCount(sums.blocks)} blocks ({formatShare(sums.blocks, sums.hits)}) and {formatCount(sums.wouldBlocks)} would-blocks in the
                last {period}.
            </p>
            <figure aria-label={`Blocks and would-blocks in the last ${period}`} className="grid gap-2">
                <ChartContainer config={CHART} className="aspect-auto h-64 w-full">
                    <BarChart data={series(metrics.data)} margin={{ left: 0, right: 8, top: 8 }}>
                        <CartesianGrid vertical={false} />
                        <XAxis dataKey="time" tickLine={false} axisLine={false} tickMargin={8} minTickGap={32} tickFormatter={(time: string) => tick(time, period)} />
                        <YAxis allowDecimals={false} tickLine={false} axisLine={false} width={40} />
                        <ChartTooltip content={(props) => <TrafficTooltip {...props} />} />
                        <Bar dataKey="blocks" stackId="traffic" fill="var(--color-blocks)" isAnimationActive={false} />
                        <Bar dataKey="wouldBlocks" stackId="traffic" fill="var(--color-wouldBlocks)" radius={[2, 2, 0, 0]} isAnimationActive={false} />
                    </BarChart>
                </ChartContainer>
                <figcaption>
                    <ChartLegend config={CHART} />
                </figcaption>
            </figure>
        </div>
    );
}

/** An axis label in the browser's zone: the time for an hour or a day, the day and hour for a week. */
function tick(time: string, period: Period): string {
    const options: Intl.DateTimeFormatOptions = period === 'week' ? { weekday: 'short', hour: 'numeric' } : { hour: 'numeric', minute: '2-digit' };

    return new Intl.DateTimeFormat(undefined, options).format(new Date(time));
}

function TrafficTooltip({ active, payload }: TooltipContentProps) {
    const point: unknown = payload[0]?.payload;

    if (!active || !isPoint(point)) {
        return null;
    }

    const { local, utc } = formatTime(point.time);
    const rows: [string, number, string?][] = [
        ['Blocks', point.blocks, 'bg-destructive'],
        ['Would-blocks', point.wouldBlocks, 'bg-warning'],
        ['Checks', point.hits],
    ];

    return (
        <div className="grid min-w-44 gap-1.5 rounded-lg border bg-background px-2.5 py-1.5 text-xs shadow-xl">
            <div>
                <p className="font-medium">{local}</p>
                <p className="text-muted-foreground">{utc}</p>
            </div>
            <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-0.5">
                {rows.map(([label, value, swatch]) => (
                    <div key={label} className="contents">
                        <dt className="flex items-center gap-1.5 text-muted-foreground">
                            <span className={cn('size-2 rounded-[2px]', swatch ?? 'bg-transparent')} aria-hidden />
                            {label}
                        </dt>
                        <dd className="text-right font-mono font-medium tabular-nums">{formatCount(value)}</dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

function isPoint(value: unknown): value is Point {
    return typeof value === 'object' && value !== null && 'time' in value && 'blocks' in value && 'wouldBlocks' in value && 'hits' in value;
}
