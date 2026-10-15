import { Link } from 'wouter';
import { QueryError } from '@/components/query-error';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { formatCount } from '@/lib/format';
import { useRuleCounts } from '@/lib/queries';

/** The enabled rules by mode, and the disabled ones (SPEC-021 AC-4). */
export function RulesCard() {
    const counts = useRuleCounts();
    const data = counts.data;

    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    <h2>Rules</h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-1">
                {counts.isError && <QueryError error={counts.error} />}
                {data !== undefined && (
                    <>
                        <p className="flex items-baseline gap-2">
                            <span className="text-2xl font-semibold tabular-nums">{formatCount(data.enforcing + data.dry_run)}</span>
                            <span className="text-sm text-muted-foreground">enabled</span>
                        </p>
                        <p className="text-sm">
                            {formatCount(data.enforcing)} enforcing, {formatCount(data.dry_run)} dry-run
                        </p>
                        <p className="text-sm text-muted-foreground">{formatCount(data.disabled)} disabled</p>
                    </>
                )}
            </CardContent>
            <CardFooter className="mt-auto">
                <Link href="/rules" className="text-sm underline underline-offset-4">
                    See the rules
                </Link>
            </CardFooter>
        </Card>
    );
}
