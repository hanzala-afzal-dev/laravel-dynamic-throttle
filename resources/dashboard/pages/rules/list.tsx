import { Copy, Pencil, Plus, Search } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { Link, useLocation, useSearch } from 'wouter';
import { ChangeOutcome, ConfirmDialog } from '@/components/confirm-dialog';
import { QueryError } from '@/components/query-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useConfirmedChange } from '@/lib/confirmed-change';
import { formatCount } from '@/lib/format';
import type { Metrics } from '@/lib/metrics';
import { useCanManage, useMetrics } from '@/lib/queries';
import { filterQuery, limitLabel, methodLabel, modeLabel, type Rule, type RuleFilters, scopeLabel, useRules } from '@/lib/rules';

/** How long the search waits after the last key before it asks the API. */
const SEARCH_DELAY_MS = 300;

/** The rules in evaluation order, with their search and filters in the page's URL (SPEC-022 AC-2 to AC-4). */
export function RulesList() {
    const [filters, setFilters] = useFilters();
    const [search, setSearch] = useState(filters.search);
    const rules = useRules(filters);
    const lastHour = useMetrics('hour').data;
    const canManage = useCanManage();
    const id = useId();
    const filtered = filters.search !== '' || filters.enabled !== '' || filters.mode !== '';

    // The search goes to the URL, and so to the API, once typing pauses.
    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const timer = setTimeout(() => setFilters({ ...filters, search, page: 1 }), SEARCH_DELAY_MS);

        return () => clearTimeout(timer);
    }, [search, filters, setFilters]);

    return (
        <section className="grid gap-6">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Rules</h1>
                    <p className="mt-1 text-sm text-muted-foreground">In the order they are evaluated: by priority, then by id.</p>
                </div>
                {canManage && (
                    <Button asChild>
                        <Link href="/rules/new">
                            <Plus aria-hidden />
                            New rule
                        </Link>
                    </Button>
                )}
            </div>

            <div className="flex flex-wrap items-end gap-3">
                <div className="relative w-full max-w-xs">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                    <Input type="search" aria-label="Search rules" placeholder="Search by name or pattern" className="pl-8" value={search} onChange={(event) => setSearch(event.target.value)} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-enabled`}>Status</Label>
                    <NativeSelect id={`${id}-enabled`} value={filters.enabled} onChange={(event) => setFilters({ ...filters, enabled: asEnabled(event.target.value), page: 1 })}>
                        <option value="">All</option>
                        <option value="true">Enabled</option>
                        <option value="false">Disabled</option>
                    </NativeSelect>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-mode`}>Mode</Label>
                    <NativeSelect id={`${id}-mode`} value={filters.mode} onChange={(event) => setFilters({ ...filters, mode: asMode(event.target.value), page: 1 })}>
                        <option value="">All</option>
                        <option value="enforce">Enforcing</option>
                        <option value="dry_run">Dry-run</option>
                    </NativeSelect>
                </div>
            </div>

            <Card className="py-2">
                <CardContent className="px-2">
                    {rules.isError && <QueryError error={rules.error} />}
                    {rules.data === undefined && !rules.isError && <Skeleton className="h-40 w-full" />}
                    {rules.data !== undefined && rules.data.total === 0 && (
                        <div className="grid justify-items-start gap-3 p-4">
                            <p className="text-sm text-muted-foreground">{filtered ? 'No rule matches the search and filters.' : 'No rule yet.'}</p>
                        </div>
                    )}
                    {rules.data !== undefined && rules.data.total > 0 && (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Applies to</TableHead>
                                    <TableHead>Per</TableHead>
                                    <TableHead>Limit</TableHead>
                                    <TableHead>Mode</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Last hour</TableHead>
                                    {canManage && <TableHead className="sr-only">Actions</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rules.data.data.map((rule) => (
                                    <RuleRow key={rule.id} rule={rule} canManage={canManage} lastHour={lastHour} />
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {rules.data !== undefined && rules.data.last_page > 1 && (
                <nav aria-label="Pages of rules" className="flex items-center justify-end gap-3 text-sm">
                    <Button variant="outline" size="sm" disabled={filters.page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>
                        Previous page
                    </Button>
                    <span>
                        Page {rules.data.current_page} of {rules.data.last_page}
                    </span>
                    <Button variant="outline" size="sm" disabled={filters.page >= rules.data.last_page} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>
                        Next page
                    </Button>
                </nav>
            )}
        </section>
    );
}

export default RulesList;

function RuleRow({ rule, canManage, lastHour }: { rule: Rule; canManage: boolean; lastHour: Metrics | undefined }) {
    const change = useConfirmedChange([['rules']]);

    return (
        <TableRow>
            <TableCell className="max-w-56 truncate">
                <Link href={`/rules/${rule.id}`} className="font-medium underline-offset-4 hover:underline">
                    {rule.name}
                </Link>
            </TableCell>
            <TableCell>
                <span className="text-muted-foreground">{methodLabel(rule.method)}</span> <code className="font-mono text-xs">{rule.pattern}</code>
            </TableCell>
            <TableCell>{scopeLabel(rule)}</TableCell>
            <TableCell>{limitLabel(rule)}</TableCell>
            <TableCell>
                <Badge variant={rule.mode === 'enforce' ? 'default' : 'secondary'}>{modeLabel(rule.mode)}</Badge>
            </TableCell>
            <TableCell>
                {canManage ? (
                    <div className="grid gap-1">
                        <label className="flex items-center gap-2">
                            <Switch
                                checked={rule.enabled}
                                disabled={change.pending}
                                aria-label={`${rule.name} enabled`}
                                onCheckedChange={(enabled) => change.send({ method: 'PATCH', path: `rules/${rule.id}`, body: { enabled } })}
                            />
                            <span>{rule.enabled ? 'Enabled' : 'Disabled'}</span>
                        </label>
                        <ChangeOutcome change={change} />
                        <ConfirmDialog change={change} />
                    </div>
                ) : (
                    <span className={rule.enabled ? undefined : 'text-muted-foreground'}>{rule.enabled ? 'Enabled' : 'Disabled'}</span>
                )}
            </TableCell>
            <TableCell className="text-right tabular-nums">{lastHourOf(rule, lastHour)}</TableCell>
            {canManage && (
                <TableCell className="text-right">
                    <div className="flex justify-end gap-1">
                        <Button variant="ghost" size="icon" asChild>
                            <Link href={`/rules/${rule.id}/edit`} aria-label={`Edit ${rule.name}`}>
                                <Pencil aria-hidden />
                            </Link>
                        </Button>
                        <Button variant="ghost" size="icon" asChild>
                            <Link href={`/rules/new?from=${rule.id}`} aria-label={`Duplicate ${rule.name}`}>
                                <Copy aria-hidden />
                            </Link>
                        </Button>
                    </div>
                </TableCell>
            )}
        </TableRow>
    );
}

/** A rule's blocks in the last hour, or its would-blocks in dry-run, from api/metrics. */
function lastHourOf(rule: Rule, metrics: Metrics | undefined): string {
    const series = metrics?.rules.find((candidate) => candidate.id === rule.id);
    const sum = (values: number[] | undefined) => (values ?? []).reduce((total, value) => total + value, 0);
    const count = rule.mode === 'enforce' ? sum(series?.blocks) : sum(series?.would_blocks);
    const noun = rule.mode === 'enforce' ? 'block' : 'would-block';

    return count === 0 ? '—' : `${formatCount(count)} ${count === 1 ? noun : `${noun}s`}`;
}

/** The filters the page's URL holds, and a setter that writes them back to it. */
function useFilters(): [RuleFilters, (filters: RuleFilters) => void] {
    const params = new URLSearchParams(useSearch());
    const [location, navigate] = useLocation();
    const page = Number.parseInt(params.get('page') ?? '1', 10);
    const filters: RuleFilters = {
        search: params.get('search') ?? '',
        enabled: asEnabled(params.get('enabled') ?? ''),
        mode: asMode(params.get('mode') ?? ''),
        page: Number.isInteger(page) && page > 0 ? page : 1,
    };

    return [
        filters,
        (next: RuleFilters) => {
            const query = filterQuery(next);
            navigate(query === '' ? location : `${location}?${query}`, { replace: true });
        },
    ];
}

function asEnabled(value: string): RuleFilters['enabled'] {
    return value === 'true' || value === 'false' ? value : '';
}

function asMode(value: string): RuleFilters['mode'] {
    return value === 'enforce' || value === 'dry_run' ? value : '';
}
