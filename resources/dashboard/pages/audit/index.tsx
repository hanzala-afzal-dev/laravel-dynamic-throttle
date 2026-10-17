import { Fragment, useId, useState } from 'react';
import { Link, useLocation, useSearch } from 'wouter';
import { QueryError } from '@/components/query-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AUDIT_ACTIONS, type AuditFilters, auditFiltersOf, auditQuery, changeSummary, localInputOf, NO_AUDIT_FILTERS, useAuditLog, utcOf } from '@/lib/audit';
import type { AuditEntry } from '@/lib/queries';
import { Time } from '@/lib/time';

/**
 * The Audit log (SPEC-025): every change to the rules, the exemptions and the emergency
 * settings, newest first, filtered from the page's URL (decision 1), with each entry's
 * before and after on demand (decision 3). It only reads, for viewers and managers alike.
 */
export function AuditLogPage() {
    const [filters, setFilters] = useAuditFilters();
    const log = useAuditLog(filters);
    const entries = log.data?.pages.flatMap((page) => page.data) ?? [];
    const filtered = auditQuery(filters) !== '';

    return (
        <section className="grid gap-6">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">Audit log</h1>
                <p className="mt-1 text-sm text-muted-foreground">Every change to the rules, the exemptions and the emergency settings, from the dashboard and the CLI.</p>
            </div>

            {/* Keyed by the filters, so its fields follow the URL, such as after "Clear filters". */}
            <FilterForm key={auditQuery(filters)} filters={filters} onApply={setFilters} />

            <Card className="py-2">
                <CardContent className="grid gap-2 px-2">
                    {log.isError && <QueryError error={log.error} />}
                    {log.data === undefined && !log.isError && <Skeleton className="h-40 w-full" />}
                    {log.data !== undefined && entries.length === 0 && (
                        <p className="p-4 text-sm text-muted-foreground">{filtered ? 'No entry matches the filters.' : 'No change has been recorded yet.'}</p>
                    )}
                    {entries.length > 0 && (
                        <Table>
                            <caption className="sr-only">Audit log entries</caption>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>When</TableHead>
                                    <TableHead>Who</TableHead>
                                    <TableHead>Action</TableHead>
                                    <TableHead>Target</TableHead>
                                    <TableHead>Change</TableHead>
                                    <TableHead className="sr-only">Details</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entries.map((entry) => (
                                    <EntryRows key={entry.id} entry={entry} />
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {log.hasNextPage && (
                <div className="flex justify-center">
                    <Button variant="outline" onClick={() => void log.fetchNextPage()} disabled={log.isFetchingNextPage}>
                        Load more
                    </Button>
                </div>
            )}
        </section>
    );
}

export default AuditLogPage;

function EntryRows({ entry }: { entry: AuditEntry }) {
    const [open, setOpen] = useState(false);
    const id = useId();
    const summary = changeSummary(entry);

    return (
        <Fragment>
            <TableRow>
                <TableCell className="whitespace-nowrap">{entry.created_at === null ? '—' : <Time value={entry.created_at} />}</TableCell>
                <TableCell className="max-w-56 truncate">
                    <code className="font-mono text-xs">{entry.actor}</code>
                </TableCell>
                <TableCell className="whitespace-nowrap">{AUDIT_ACTIONS[entry.action] ?? entry.action}</TableCell>
                <TableCell className="whitespace-nowrap">
                    <Target target={entry.target} />
                </TableCell>
                <TableCell className="max-w-96 truncate font-mono text-xs" title={summary}>
                    {summary}
                </TableCell>
                <TableCell className="text-right">
                    <Button variant="ghost" size="sm" aria-expanded={open} aria-controls={id} onClick={() => setOpen(!open)}>
                        Details
                    </Button>
                </TableCell>
            </TableRow>
            {open && (
                <TableRow>
                    <TableCell colSpan={6} className="whitespace-normal">
                        <section id={id} aria-label={`Details of entry ${entry.id}`} className="grid gap-4 py-2 md:grid-cols-2">
                            <State title="Before" state={entry.before} />
                            <State title="After" state={entry.after} />
                            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm md:col-span-2">
                                <dt className="text-muted-foreground">IP</dt>
                                <dd className="font-mono text-xs">{entry.ip ?? 'Not recorded'}</dd>
                                <dt className="text-muted-foreground">User agent</dt>
                                <dd className="font-mono text-xs break-all">{entry.user_agent ?? 'Not recorded'}</dd>
                            </dl>
                        </section>
                    </TableCell>
                </TableRow>
            )}
        </Fragment>
    );
}

/** The state before or after the change, in full, as written. */
function State({ title, state }: { title: string; state: Record<string, unknown> | null }) {
    return (
        <div className="grid content-start gap-1">
            <h3 className="text-sm font-medium">{title}</h3>
            <pre className="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs whitespace-pre-wrap break-all">{state === null ? 'None' : JSON.stringify(state, null, 2)}</pre>
        </div>
    );
}

/** A rule's target links to its page; the others show as written. */
function Target({ target }: { target: string | null }) {
    const rule = target === null ? null : /^rule:([1-9]\d{0,17})$/.exec(target);

    if (target === null) {
        return '—';
    }

    return rule === null ? (
        <code className="font-mono text-xs">{target}</code>
    ) : (
        <Link href={`/rules/${rule[1]}`} className="font-mono text-xs underline-offset-4 hover:underline">
            {target}
        </Link>
    );
}

/** The filters, applied together; times entered in the browser's zone (decision 4). */
function FilterForm({ filters, onApply }: { filters: AuditFilters; onApply: (filters: AuditFilters) => void }) {
    const [values, setValues] = useState({ ...filters, from: localInputOf(filters.from), until: localInputOf(filters.until) });
    const id = useId();
    const set = (key: keyof AuditFilters, value: string) => setValues((current) => ({ ...current, [key]: value }));

    return (
        <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                onApply({ action: values.action, target: values.target.trim(), actor: values.actor.trim(), from: utcOf(values.from), until: utcOf(values.until) });
            }}
        >
            <div className="grid gap-1.5">
                <Label htmlFor={`${id}-action`}>Action</Label>
                <NativeSelect id={`${id}-action`} value={values.action} onChange={(event) => set('action', event.target.value)}>
                    <option value="">Any action</option>
                    {Object.entries(AUDIT_ACTIONS).map(([action, words]) => (
                        <option key={action} value={action}>
                            {words}
                        </option>
                    ))}
                </NativeSelect>
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${id}-target`}>Target</Label>
                <Input id={`${id}-target`} className="w-40 font-mono" placeholder="rule:12" maxLength={255} value={values.target} onChange={(event) => set('target', event.target.value)} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${id}-actor`}>Actor</Label>
                <Input id={`${id}-actor`} className="w-56 font-mono" placeholder="cli:deploy" maxLength={255} value={values.actor} onChange={(event) => set('actor', event.target.value)} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${id}-from`}>From</Label>
                <Input id={`${id}-from`} type="datetime-local" className="w-52" value={values.from} onChange={(event) => set('from', event.target.value)} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${id}-until`}>Until</Label>
                <Input id={`${id}-until`} type="datetime-local" className="w-52" value={values.until} onChange={(event) => set('until', event.target.value)} />
            </div>
            <div className="flex gap-2">
                <Button type="submit">Apply filters</Button>
                <Button type="button" variant="outline" onClick={() => onApply(NO_AUDIT_FILTERS)}>
                    Clear filters
                </Button>
            </div>
        </form>
    );
}

/** The filters the page's URL holds, and a setter that writes them to it, as a new history entry. */
function useAuditFilters(): [AuditFilters, (filters: AuditFilters) => void] {
    const filters = auditFiltersOf(useSearch());
    const [location, navigate] = useLocation();

    return [
        filters,
        (next: AuditFilters) => {
            const query = auditQuery(next);
            navigate(query === '' ? location : `${location}?${query}`);
        },
    ];
}
