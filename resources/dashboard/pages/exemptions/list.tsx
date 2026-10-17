import { useEffect, useId } from 'react';
import { Link, useLocation, useSearch } from 'wouter';
import { ExemptionWho } from '@/components/exemption-who';
import { QueryError } from '@/components/query-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type Exemption, type ExemptionFilters, filtersOf, pageQuery, useExemptions } from '@/lib/exemptions';
import { type Rule, useRuleName, useRules } from '@/lib/rules';
import { Time } from '@/lib/time';

/** Every rule's first page, in evaluation order: the filter's choices and the rows' names (decision 6). */
const FIRST_RULES = { search: '', enabled: '', mode: '', page: 1 } as const;

/** Who is exempt from which rule, and until when, with the filters in the page's URL (SPEC-023 AC-2, AC-3). */
export function ExemptionsList() {
    const [filters, setFilters] = useFilters();
    const exemptions = useExemptions(filters);
    const lastPage = exemptions.isPlaceholderData ? undefined : exemptions.data?.last_page;
    // undefined until the rules load, so no row asks for a name the list will give.
    const rules = useRules(FIRST_RULES).data?.data;
    const id = useId();

    // A page past the last, from an old link or after exemptions ended, goes to the last (as SPEC-022 AC-17).
    useEffect(() => {
        if (lastPage !== undefined && filters.page > lastPage) {
            setFilters({ ...filters, page: lastPage });
        }
    }, [lastPage, filters, setFilters]);

    return (
        <section className="grid gap-6">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">Exemptions</h1>
                <p className="mt-1 text-sm text-muted-foreground">Who skips one rule or every rule, and until when.</p>
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
                        <option value="">All rules</option>
                        <option value="none">Every rule</option>
                        {rules?.map((rule) => (
                            <option key={rule.id} value={String(rule.id)}>
                                {rule.name}
                            </option>
                        ))}
                        {/^\d+$/.test(filters.rule) && rules?.some((rule) => String(rule.id) === filters.rule) !== true && (
                            <option value={filters.rule}>
                                <RuleName id={Number(filters.rule)} rules={rules} />
                            </option>
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
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {exemptions.data.data.map((exemption) => (
                                    <ExemptionRow key={exemption.id} exemption={exemption} rules={rules} />
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

function ExemptionRow({ exemption, rules }: { exemption: Exemption; rules: Rule[] | undefined }) {
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
                        <RuleName id={exemption.rule_id} rules={rules} />
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
        </TableRow>
    );
}

/** A rule's name from the first rules, or asked for by its id once they load without it. */
function RuleName({ id, rules }: { id: number; rules: Rule[] | undefined }) {
    const known = rules?.find((rule) => rule.id === id);
    const asked = useRuleName(id, rules !== undefined && known === undefined);

    return known?.name ?? asked.data ?? `Rule #${id}`;
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
