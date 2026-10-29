import { RefreshCw } from 'lucide-react';
import { useId } from 'react';
import { QueryError } from '@/components/query-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type CheckStatus, useHealth } from '@/lib/queries';
import { CHECK_PURPOSES } from '@/lib/settings-page';
import { cn } from '@/lib/utils';

const ORDER: CheckStatus[] = ['error', 'warning', 'ok', 'skipped'];
const LABELS: Record<CheckStatus, string> = { error: 'Error', warning: 'Warning', ok: 'OK', skipped: 'Skipped' };
const OVERALL: Record<CheckStatus, string> = { error: 'Errors', warning: 'Warnings', ok: 'Healthy', skipped: 'Healthy' };

/** Every health check, worst first, with what it looks for and what to do (SPEC-031 AC-3). */
export function SettingsHealthCard() {
    const health = useHealth();
    const id = useId();
    const report = health.data;
    const checks = report === undefined ? [] : [...report.checks].sort((a, b) => ORDER.indexOf(a.status) - ORDER.indexOf(b.status));

    return (
        <Card role="region" aria-labelledby={id}>
            <CardHeader className="flex items-center justify-between">
                <CardTitle>
                    <h2 id={id}>Health</h2>
                </CardTitle>
                <Button variant="ghost" size="sm" onClick={() => void health.refetch()} disabled={health.isFetching}>
                    <RefreshCw className={cn(health.isFetching && 'animate-spin')} aria-hidden />
                    Check again
                </Button>
            </CardHeader>
            <CardContent className="grid gap-4">
                {health.isError && <QueryError error={health.error} />}
                {report === undefined && !health.isError && <Skeleton className="h-40 w-full" />}
                {report !== undefined && (
                    <>
                        <p role="status" className={cn('text-2xl font-semibold', report.status === 'error' ? 'text-destructive' : report.status === 'warning' ? 'text-warning' : 'text-success')}>{OVERALL[report.status]}</p>
                        <Table>
                            <caption className="sr-only">Checks</caption>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Check</TableHead>
                                    <TableHead>What it found, and what to do</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {checks.map((check) => (
                                    <TableRow key={check.check}>
                                        <TableCell className="align-top">
                                            <Badge variant={check.status === 'error' ? 'destructive' : check.status === 'ok' ? 'secondary' : 'outline'}>{LABELS[check.status]}</Badge>
                                        </TableCell>
                                        <TableCell className="max-w-72 align-top whitespace-normal">
                                            <code className="font-mono text-xs font-medium">{check.check}</code>
                                            {/* Labelled, so under OK it never reads as what the check found (issue #153). */}
                                            {CHECK_PURPOSES[check.check] !== undefined && <p className="mt-1 text-xs text-muted-foreground">Looks for: {CHECK_PURPOSES[check.check]}</p>}
                                        </TableCell>
                                        <TableCell className="align-top whitespace-normal">{check.message}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
