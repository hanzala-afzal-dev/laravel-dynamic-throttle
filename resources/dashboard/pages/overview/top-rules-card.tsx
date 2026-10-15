import { Link } from 'wouter';
import { QueryError } from '@/components/query-error';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatCount } from '@/lib/format';
import { type Period, topRules } from '@/lib/metrics';
import { useMetrics } from '@/lib/queries';

/** The rules that blocked most in the chart's period (SPEC-021 AC-7). */
export function TopRulesCard({ period, className }: { period: Period; className?: string }) {
    const metrics = useMetrics(period);
    const rules = metrics.data === undefined ? undefined : topRules(metrics.data);

    return (
        <Card className={className}>
            <CardHeader>
                <CardTitle>
                    <h2>Top rules</h2>
                </CardTitle>
                <CardDescription>By blocks, then would-blocks, in the last {period}</CardDescription>
            </CardHeader>
            <CardContent>
                {metrics.isError && <QueryError error={metrics.error} />}
                {rules?.length === 0 && <p className="text-sm text-muted-foreground">No rule blocked a request in the last {period}.</p>}
                {rules !== undefined && rules.length > 0 && (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Rule</TableHead>
                                <TableHead className="text-right">Checks</TableHead>
                                <TableHead className="text-right">Blocks</TableHead>
                                <TableHead className="text-right">Would-blocks</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rules.map((rule) => (
                                <TableRow key={rule.id}>
                                    <TableCell className="max-w-48 truncate">
                                        {rule.name === null ? (
                                            <span className="text-muted-foreground">Deleted rule #{rule.id}</span>
                                        ) : (
                                            <Link href={`/rules/${rule.id}`} className="font-medium underline-offset-4 hover:underline">
                                                {rule.name}
                                            </Link>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">{formatCount(rule.hits)}</TableCell>
                                    <TableCell className="text-right tabular-nums">{formatCount(rule.blocks)}</TableCell>
                                    <TableCell className="text-right tabular-nums">{formatCount(rule.wouldBlocks)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}
