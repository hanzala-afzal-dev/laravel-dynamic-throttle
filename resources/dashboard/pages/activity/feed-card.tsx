import { Pause, Play } from 'lucide-react';
import { useId, useState } from 'react';
import { Link } from 'wouter';
import { QueryError } from '@/components/query-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type FeedEntry, useLiveFeed } from '@/lib/feed';
import { type ConfigValue, useEffectiveConfig } from '@/lib/settings-page';

type Outcome = '' | FeedEntry['outcome'];

/**
 * The live feed (SPEC-024 AC-1 to AC-4): the last hour's blocks and would-blocks,
 * newest first, polled for what is new, with filters that run in the page
 * (decision 2), and why there is none.
 */
export function FeedCard() {
    const [paused, setPaused] = useState(false);
    const feed = useLiveFeed(paused);
    const telemetry = useTelemetry();
    const [outcome, setOutcome] = useState<Outcome>('');
    const [rule, setRule] = useState('');
    const id = useId();
    const entries = feed.data ?? [];
    const shown = entries.filter((entry) => (outcome === '' || entry.outcome === outcome) && (rule === '' || String(entry.rule) === rule));

    return (
        <Card role="region" aria-labelledby={id}>
            <CardHeader className="flex flex-wrap items-start justify-between gap-2">
                <div className="grid gap-1.5">
                    <CardTitle>
                        <h2 id={id}>Live feed</h2>
                    </CardTitle>
                    <CardDescription>The last hour's blocks and would-blocks, newest first. New ones arrive every 5 seconds.</CardDescription>
                </div>
                {Array.isArray(feed.data) && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => {
                            setPaused(!paused);

                            // What came while paused, at once.
                            if (paused) {
                                void feed.refetch();
                            }
                        }}
                    >
                        {paused ? <Play aria-hidden /> : <Pause aria-hidden />}
                        {paused ? 'Resume' : 'Pause'}
                    </Button>
                )}
            </CardHeader>
            <CardContent className="grid gap-4">
                {feed.isError && <QueryError error={feed.error} />}
                {feed.data === undefined && !feed.isError && <Skeleton className="h-40 w-full" />}
                {feed.data === null && <p className="text-sm text-muted-foreground">This counter store keeps no live feed. Redis and Valkey do.</p>}
                {Array.isArray(feed.data) && telemetry.off && <p className="text-sm text-muted-foreground">The live feed is off: telemetry.live_feed in config/dynamic-throttle.php.</p>}
                {Array.isArray(feed.data) && !telemetry.off && telemetry.sampleRate > 0 && telemetry.sampleRate < 1 && (
                    <p className="text-sm text-muted-foreground">
                        Telemetry samples requests (telemetry.sample_rate {telemetry.sampleRate}), so this shows about one block in {Math.round(1 / telemetry.sampleRate)}.
                    </p>
                )}
                {Array.isArray(feed.data) && entries.length === 0 && !telemetry.off && telemetry.known && <p className="text-sm text-muted-foreground">Nothing was blocked in the last hour.</p>}
                {entries.length > 0 && (
                    <>
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${id}-outcome`}>Outcome</Label>
                                <NativeSelect id={`${id}-outcome`} value={outcome} onChange={(event) => setOutcome(event.target.value as Outcome)}>
                                    <option value="">All</option>
                                    <option value="blocked">Blocked</option>
                                    <option value="would_block">Would block</option>
                                </NativeSelect>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${id}-rule`}>Rule</Label>
                                <NativeSelect id={`${id}-rule`} className="max-w-64" value={rule} onChange={(event) => setRule(event.target.value)}>
                                    <option value="">Any rule</option>
                                    {rulesOf(entries, rule).map(([ruleId, name]) => (
                                        <option key={ruleId} value={String(ruleId)}>
                                            {name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                        </div>
                        {shown.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No block matches the filters.</p>
                        ) : (
                            <Table>
                                <caption className="sr-only">Blocks in the last hour</caption>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>When</TableHead>
                                        <TableHead>Outcome</TableHead>
                                        <TableHead>Rule</TableHead>
                                        <TableHead>Request</TableHead>
                                        <TableHead>IP</TableHead>
                                        <TableHead>User</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {shown.map((entry, index) => (
                                        <FeedRow key={`${entry.time}-${entry.rule}-${entry.identity}-${index}`} entry={entry} />
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </>
                )}
            </CardContent>
        </Card>
    );
}

export default FeedCard;

function FeedRow({ entry }: { entry: FeedEntry }) {
    return (
        <TableRow>
            <TableCell>
                <FeedTime time={entry.time} />
            </TableCell>
            <TableCell>
                <Badge variant={entry.outcome === 'blocked' ? 'destructive' : 'outline'}>{entry.outcome === 'blocked' ? 'Blocked' : 'Would block'}</Badge>
            </TableCell>
            <TableCell className="max-w-48 truncate">
                {entry.rule_name === null ? (
                    ruleLabel(entry)
                ) : (
                    <Link href={`/rules/${entry.rule}`} className="font-medium underline-offset-4 hover:underline">
                        {entry.rule_name}
                    </Link>
                )}
            </TableCell>
            <TableCell className="max-w-64 truncate font-mono text-xs">{entry.route === null ? entry.method : `${entry.method} ${entry.route}`}</TableCell>
            <TableCell className="font-mono text-xs">{entry.ip ?? '—'}</TableCell>
            <TableCell className="max-w-56 truncate font-mono text-xs">{entry.user ?? '—'}</TableCell>
        </TableRow>
    );
}

/** A time of the last hour, to the second, in the browser's zone, with its UTC time on hover. */
function FeedTime({ time }: { time: number }) {
    const date = new Date(time);
    const utc = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'medium', timeZone: 'UTC' }).format(date);

    return (
        <time dateTime={date.toISOString()} title={`${utc} UTC`}>
            {new Intl.DateTimeFormat(undefined, { timeStyle: 'medium' }).format(date)}
        </time>
    );
}

function ruleLabel(entry: FeedEntry): string {
    return entry.rule_name ?? `Rule #${entry.rule} (deleted)`;
}

/** The rules the entries name, in the order they first appear, and the chosen one even when its entries have gone. */
function rulesOf(entries: FeedEntry[], chosen: string): [number, string][] {
    const rules = new Map<number, string>();

    for (const entry of entries) {
        if (!rules.has(entry.rule)) {
            rules.set(entry.rule, ruleLabel(entry));
        }
    }

    if (/^\d+$/.test(chosen) && !rules.has(Number(chosen))) {
        rules.set(Number(chosen), `Rule #${chosen}`);
    }

    return [...rules];
}

/** Whether telemetry keeps the feed, and its sample rate, as api/config gives them (decision 5). */
function useTelemetry(): { known: boolean; off: boolean; sampleRate: number } {
    const config = useEffectiveConfig();
    const telemetry: ConfigValue | undefined = config.data?.config.telemetry;
    const values = typeof telemetry === 'object' && telemetry !== null && !Array.isArray(telemetry) ? telemetry : {};

    return {
        // Without the configuration, an empty feed says nothing, rather than something wrong.
        known: config.data !== undefined,
        off: values.enabled === false || values.live_feed === false,
        sampleRate: typeof values.sample_rate === 'number' ? values.sample_rate : 1,
    };
}
