import { CircleAlert, CircleCheck, RefreshCw, TriangleAlert } from 'lucide-react';
import { Link } from 'wouter';
import { QueryError } from '@/components/query-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { type CheckStatus, useHealth } from '@/lib/queries';
import { cn } from '@/lib/utils';

/** The checks the card lists at most; Settings shows them all (SPEC-021). */
const SHOWN = 3;

const STATUSES = {
    ok: { label: 'Healthy', icon: CircleCheck, className: 'text-success' },
    warning: { label: 'Warnings', icon: TriangleAlert, className: 'text-warning' },
    error: { label: 'Errors', icon: CircleAlert, className: 'text-destructive' },
} as const;

/** The health report in short: its status, and its first errors and warnings (SPEC-021 AC-3). */
export function HealthCard() {
    const health = useHealth();
    const report = health.data;
    const problems = report === undefined ? [] : [...report.checks.filter((check) => check.status === 'error'), ...report.checks.filter((check) => check.status === 'warning')].slice(0, SHOWN);
    const status = report === undefined ? undefined : STATUSES[report.status === 'skipped' ? 'ok' : report.status];

    return (
        <Card>
            <CardHeader className="flex items-center justify-between">
                <CardTitle>
                    <h2>Health</h2>
                </CardTitle>
                <Button variant="ghost" size="sm" onClick={() => void health.refetch()} disabled={health.isFetching}>
                    <RefreshCw className={cn(health.isFetching && 'animate-spin')} aria-hidden />
                    Check again
                </Button>
            </CardHeader>
            <CardContent className="grid gap-3">
                {health.isError && <QueryError error={health.error} />}
                {status !== undefined && (
                    <p className={cn('flex items-center gap-2 text-2xl font-semibold', status.className)}>
                        <status.icon className="size-6" aria-hidden />
                        {status.label}
                    </p>
                )}
                {problems.length > 0 && (
                    <ul className="grid gap-1.5 text-sm">
                        {problems.map((problem) => (
                            <li key={problem.check} className="flex gap-2">
                                <Dot status={problem.status} />
                                <span>
                                    <span className="sr-only">{problem.status === 'error' ? 'Error: ' : 'Warning: '}</span>
                                    {problem.message}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
            <CardFooter className="mt-auto">
                <Link href="/settings" className="text-sm underline underline-offset-4">
                    See every check
                </Link>
            </CardFooter>
        </Card>
    );
}

function Dot({ status }: { status: CheckStatus }) {
    return <span className={cn('mt-1.5 size-2 shrink-0 rounded-full', status === 'error' ? 'bg-destructive' : 'bg-warning')} aria-hidden />;
}
