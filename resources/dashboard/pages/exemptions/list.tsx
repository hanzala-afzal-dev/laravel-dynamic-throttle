import { Trash2 } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { Link, useLocation, useSearch } from 'wouter';
import { ChangeOutcome } from '@/components/confirm-dialog';
import { ExemptionWho } from '@/components/exemption-who';
import { QueryError } from '@/components/query-error';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useConfirmedChange } from '@/lib/confirmed-change';
import { type Exemption, type ExemptionFilters, filtersOf, pageQuery, useExemptions, whoLabel, whoOf } from '@/lib/exemptions';
import { useCanManage } from '@/lib/queries';
import { useRuleNames, useRules } from '@/lib/rules';
import { Time } from '@/lib/time';
import { AddExemptionDialog, EXEMPTION_QUERIES } from '@/pages/exemptions/add-dialog';

/** Every rule's first page, in evaluation order: the filter's choices and the rows' names (decision 6). */
const FIRST_RULES = { search: '', enabled: '', mode: '', page: 1 } as const;

/** Who is exempt from which rule, and until when, with the filters in the page's URL (SPEC-023 AC-2, AC-3, AC-6, AC-7). */
export function ExemptionsList() {
    const [filters, setFilters] = useFilters();
    const search = useSearch();
    // An IP from Activity's "Exempt…" (SPEC-024 AC-5), taken once: the dialog opens filled with it.
    const [exempt] = useState(() => (new URLSearchParams(search).get('exempt') ?? '').trim().slice(0, 100));
    const exemptions = useExemptions(filters);
    const lastPage = exemptions.isPlaceholderData ? undefined : exemptions.data?.last_page;
    // undefined until the rules load, so no row asks for a name the list will give.
    const rules = useRules(FIRST_RULES).data?.data;
    // The rules beyond the first 100 that the rows or the filter name, asked for together once those load (decision 6).
    const filteredRule = /^\d+$/.test(filters.rule) ? Number(filters.rule) : null;
    const far = rules === undefined ? [] : [...(exemptions.data?.data ?? []).map((exemption) => exemption.rule_id), filteredRule].filter((ruleId): ruleId is number => ruleId !== null && !rules.some((rule) => rule.id === ruleId));
    const farNames = useRuleNames(far).data;
    const nameOf = (ruleId: number) => rules?.find((rule) => rule.id === ruleId)?.name ?? farNames?.get(ruleId) ?? `Rule #${ruleId}`;
    const canManage = useCanManage();
    const id = useId();

    // Out of the URL once taken, so reloading the page does not open the dialog again.
    useEffect(() => {
        if (new URLSearchParams(search).has('exempt')) {
            setFilters(filters);
        }
    }, [search, filters, setFilters]);

    // A page past the last, from an old link or after exemptions ended, goes to the last (as SPEC-022 AC-17).
    useEffect(() => {
        if (lastPage !== undefined && filters.page > lastPage) {
            setFilters({ ...filters, page: lastPage });
        }
    }, [lastPage, filters, setFilters]);

    return (
        <section className="grid grid-cols-1 gap-6">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Exemptions</h1>
                    <p className="mt-1 text-sm text-muted-foreground">Who skips one rule or every rule, and until when.</p>
                </div>
                {canManage && <AddExemptionDialog rules={rules} rule={filteredRule === null ? '' : filters.rule} nameOf={nameOf} start={exempt} />}
            </div>

            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-state`}>State</Label>
                    <NativeSelect id={`${id}-state`} value={filters.state} onChange={(event) => setFilters({ ...filters, state: filtersOf(`state=${event.target.value}`).state, page: 1 })}>
                        <option value="active">Active</option>
                        <option value="expired">Expired</option>
                        <option value="all">All</option>
                    </NativeSelect>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-subject`}>Subject</Label>
                    <NativeSelect id={`${id}-subject`} value={filters.subject} onChange={(event) => setFilters({ ...filters, subject: filtersOf(`subject=${event.target.value}`).subject, page: 1 })}>
                        <option value="">All</option>
                        <option value="ip">IP</option>
                        <option value="user">User</option>
                        <option value="custom">Custom</option>
                    </NativeSelect>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-rule`}>Rule</Label>
                    <NativeSelect id={`${id}-rule`} className="max-w-64" value={filters.rule} onChange={(event) => setFilters({ ...filters, rule: event.target.value, page: 1 })}>
                        <option value="">Any rule</option>
                        <option value="none">Every rule</option>
                        {rules?.map((rule) => (
                            <option key={rule.id} value={String(rule.id)}>
                                {rule.name}
                            </option>
                        ))}
                        {/^\d+$/.test(filters.rule) && rules?.some((rule) => String(rule.id) === filters.rule) !== true && (
                            <option value={filters.rule}>{nameOf(Number(filters.rule))}</option>
                        )}
                    </NativeSelect>
                </div>
            </div>

            <Card className="py-2">
                <CardContent className="px-2">
                    {exemptions.isError && <QueryError error={exemptions.error} />}
                    {exemptions.data === undefined && !exemptions.isError && <Skeleton className="h-40 w-full" />}
                    {exemptions.data !== undefined && exemptions.data.total === 0 && <p className="p-4 text-sm text-muted-foreground">{emptyMessage(filters)}</p>}
                    {exemptions.data !== undefined && exemptions.data.total > 0 && (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Who</TableHead>
                                    <TableHead>Rule</TableHead>
                                    <TableHead>Reason</TableHead>
                                    <TableHead>Ends</TableHead>
                                    <TableHead>Added</TableHead>
                                    {canManage && <TableHead className="sr-only">Actions</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {exemptions.data.data.map((exemption) => (
                                    <ExemptionRow key={exemption.id} exemption={exemption} nameOf={nameOf} canManage={canManage} />
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {exemptions.data !== undefined && exemptions.data.last_page > 1 && (
                <nav aria-label="Pages of exemptions" className="flex items-center justify-end gap-3 text-sm">
                    <Button variant="outline" size="sm" disabled={filters.page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>
                        Previous page
                    </Button>
                    <span>
                        Page {exemptions.data.current_page} of {exemptions.data.last_page}
                    </span>
                    <Button variant="outline" size="sm" disabled={filters.page >= exemptions.data.last_page} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>
                        Next page
                    </Button>
                </nav>
            )}
        </section>
    );
}

export default ExemptionsList;

function ExemptionRow({ exemption, nameOf, canManage }: { exemption: Exemption; nameOf: (id: number) => string; canManage: boolean }) {
    return (
        <TableRow className={exemption.active ? undefined : 'text-muted-foreground'}>
            <TableCell>
                <ExemptionWho exemption={exemption} />
            </TableCell>
            <TableCell className="max-w-56 truncate">
                {exemption.rule_id === null ? (
                    'Every rule'
                ) : (
                    <Link href={`/rules/${exemption.rule_id}`} className="font-medium underline-offset-4 hover:underline">
                        {nameOf(exemption.rule_id)}
                    </Link>
                )}
            </TableCell>
            <TableCell className="max-w-64 truncate">{exemption.reason ?? '—'}</TableCell>
            <TableCell>
                {exemption.expires_at === null ? (
                    'Never'
                ) : exemption.active ? (
                    <Time value={exemption.expires_at} />
                ) : (
                    <>
                        Ended <Time value={exemption.expires_at} />
                    </>
                )}
            </TableCell>
            <TableCell>{exemption.created_at === null ? '—' : <Time value={exemption.created_at} />}</TableCell>
            {canManage && (
                <TableCell className="text-right">
                    <RemoveExemption exemption={exemption} rule={exemption.rule_id === null ? 'every rule' : nameOf(exemption.rule_id)} />
                </TableCell>
            )}
        </TableRow>
    );
}

/** Remove asks first, naming who, the user's class, and which rule (SPEC-023 AC-6, AC-10, decision 5). */
function RemoveExemption({ exemption, rule }: { exemption: Exemption; rule: string }) {
    const change = useConfirmedChange(EXEMPTION_QUERIES);
    const who = whoLabel(exemption);
    const { detail } = whoOf(exemption);

    return (
        <div className="grid justify-items-end gap-1">
            <AlertDialog>
                <AlertDialogTrigger asChild>
                    <Button variant="ghost" size="icon" aria-label={`Remove the exemption for ${who} on ${rule}`} disabled={change.pending}>
                        <Trash2 aria-hidden />
                    </Button>
                </AlertDialogTrigger>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        {/* The trailing space keeps the title and the description apart when read as one. */}
                        <AlertDialogTitle>
                            Remove the exemption for {who}
                            {detail === null ? '' : ` (${detail})`} on {rule}
                            {'? '}
                        </AlertDialogTitle>
                        <AlertDialogDescription>Its requests are limited again.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction className={buttonVariants({ variant: 'destructive' })} onClick={() => change.send({ method: 'DELETE', path: `exemptions/${exemption.id}`, body: {} })}>
                            Remove
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <ChangeOutcome change={change} />
        </div>
    );
}

function emptyMessage(filters: ExemptionFilters): string {
    if (filters.subject !== '' || filters.rule !== '' || filters.state === 'expired') {
        return 'No exemption matches the filters.';
    }

    return filters.state === 'active' ? 'No exemption is active.' : 'No exemption yet.';
}

/** The filters the page's URL holds, and a setter that writes them back to it. */
function useFilters(): [ExemptionFilters, (filters: ExemptionFilters) => void] {
    const filters = filtersOf(useSearch());
    const [location, navigate] = useLocation();

    return [
        filters,
        (next: ExemptionFilters) => {
            const query = pageQuery(next);
            navigate(query === '' ? location : `${location}?${query}`, { replace: true });
        },
    ];
}
