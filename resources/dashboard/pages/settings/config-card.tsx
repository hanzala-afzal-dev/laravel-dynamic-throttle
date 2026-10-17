import { useId } from 'react';
import { QueryError } from '@/components/query-error';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table';
import { configGroups, useEffectiveConfig } from '@/lib/settings-page';

/** The configuration servers run with, read-only (SPEC-031 AC-4, decision 1). */
export function ConfigCard() {
    const config = useEffectiveConfig();
    const id = useId();

    return (
        <Card role="region" aria-labelledby={id}>
            <CardHeader>
                <CardTitle>
                    <h2 id={id}>Configuration</h2>
                </CardTitle>
                <CardDescription>As the app resolved it from config/dynamic-throttle.php and its environment. Change it there, then deploy.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-6">
                {config.isError && <QueryError error={config.error} />}
                {config.data === undefined && !config.isError && <Skeleton className="h-40 w-full" />}
                {config.data !== undefined && (
                    <>
                        <Rows
                            caption="In use"
                            rows={[
                                ['Counters', config.data.driver ?? <span className="text-destructive">{config.data.driver_error}</span>],
                                ['Manifest cache store', config.data.manifest_store ?? 'default'],
                                ['Custom scopes', config.data.scopes.length === 0 ? 'None' : config.data.scopes.join(', ')],
                                ['Strict mode', config.data.strict ? 'On' : 'Off'],
                            ]}
                        />
                        {configGroups(config.data.config).map((group) => (
                            <Rows key={group.name} caption={group.name} rows={group.rows.map(([key, value]) => [<code className="font-mono text-xs">{key}</code>, <code className="font-mono text-xs break-all">{value}</code>])} />
                        ))}
                    </>
                )}
            </CardContent>
        </Card>
    );
}

export default ConfigCard;

function Rows({ caption, rows }: { caption: string; rows: [React.ReactNode, React.ReactNode][] }) {
    return (
        <Table>
            <caption className="mb-2 text-left text-sm font-medium">{caption}</caption>
            <TableBody>
                {rows.map(([key, value], index) => (
                    <TableRow key={index}>
                        <TableCell className="w-1/3 align-top text-muted-foreground">{key}</TableCell>
                        <TableCell className="whitespace-normal">{value}</TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
