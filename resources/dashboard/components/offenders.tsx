import { QueryError } from '@/components/query-error';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatCount } from '@/lib/format';
import { useOffenders } from '@/lib/queries';

/** A rule's offenders this hour, and the last hour on its tab (SPEC-022, SPEC-024). */
export function OffendersByHour({ ruleId }: { ruleId: number }) {
    return (
        <Tabs defaultValue="current">
            <TabsList aria-label="Hour">
                <TabsTrigger value="current">This hour</TabsTrigger>
                <TabsTrigger value="previous">Last hour</TabsTrigger>
            </TabsList>
            <TabsContent value="current" className="pt-3">
                <OffendersOf ruleId={ruleId} hour="current" />
            </TabsContent>
            <TabsContent value="previous" className="pt-3">
                <OffendersOf ruleId={ruleId} hour="previous" />
            </TabsContent>
        </Tabs>
    );
}

/** The clients the rule blocked most, by identity hash (ADR-0024), never a raw IP or user id. */
function OffendersOf({ ruleId, hour }: { ruleId: number; hour: 'current' | 'previous' }) {
    const offenders = useOffenders(ruleId, hour);

    if (offenders.isError) {
        return <QueryError error={offenders.error} />;
    }

    if (offenders.data === undefined) {
        return <Skeleton className="h-24 w-full" />;
    }

    const { offenders: top, clients } = offenders.data;

    if (top === null) {
        return <p className="text-sm text-muted-foreground">The counter store keeps no offenders: only the Redis driver does.</p>;
    }

    if (top.length === 0) {
        return <p className="text-sm text-muted-foreground">It blocked no client in that hour.</p>;
    }

    return (
        <div className="grid gap-2">
            {clients !== null && <p className="text-sm">{formatCount(clients)} different clients blocked</p>}
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Identity hash</TableHead>
                        <TableHead className="text-right">Blocks</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {top.map((offender) => (
                        <TableRow key={offender.hash}>
                            <TableCell>
                                <code className="font-mono text-xs" title={offender.hash}>
                                    {offender.hash.slice(0, 12)}…
                                </code>
                            </TableCell>
                            <TableCell className="text-right tabular-nums">{formatCount(offender.count)}</TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
