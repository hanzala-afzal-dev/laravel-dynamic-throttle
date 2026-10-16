import { Copy, Pencil, Power, ShieldCheck, Trash2 } from 'lucide-react';
import { type ReactNode, useId, useState } from 'react';
import { Link, useLocation } from 'wouter';
import { ChangeOutcome, ConfirmDialog } from '@/components/confirm-dialog';
import { ExemptionWho } from '@/components/exemption-who';
import { QueryError } from '@/components/query-error';
import { Badge } from '@/components/ui/badge';
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
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ApiError } from '@/lib/api';
import { type ConfirmedChange, useConfirmedChange } from '@/lib/confirmed-change';
import type { Exemption } from '@/lib/exemptions';
import { formatCount, formatShare } from '@/lib/format';
import { type Period, totals } from '@/lib/metrics';
import { type AuditEntry, useAuditOf, useCanManage, useMetrics, useOffenders } from '@/lib/queries';
import { limitLabel, methodLabel, modeLabel, type Rule, scopeLabel, useRule } from '@/lib/rules';
import { Time } from '@/lib/time';
import { TrafficCard } from '@/pages/overview/traffic-card';

/** A rule's own page: its settings, traffic, offenders, exemptions and history (SPEC-022 AC-8, AC-11). */
export function RulePage({ id }: { id: number }) {
    const found = useRule(id);
    const canManage = useCanManage();
    const [period, setPeriod] = useState<Period>('hour');

    if (found.isError) {
        return found.error instanceof ApiError && found.error.status === 404 ? (
            <section className="grid justify-items-start gap-2">
                <h1 className="text-2xl font-semibold tracking-tight">There is no rule {id}.</h1>
                <Link href="/rules" className="text-sm underline underline-offset-4">
                    Back to the rules
                </Link>
            </section>
        ) : (
            <QueryError error={found.error} />
        );
    }

    if (found.data === undefined) {
        return <Skeleton className="h-96 w-full" />;
    }

    const { rule, exemptions } = found.data;

    return (
        <section className="grid gap-6">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="grid gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight">{rule.name}</h1>
                        <Badge variant={rule.mode === 'enforce' ? 'default' : 'secondary'}>{modeLabel(rule.mode)}</Badge>
                        <Badge variant="outline">{rule.enabled ? 'Enabled' : 'Disabled'}</Badge>
                    </div>
                    {rule.description !== null && <p className="max-w-2xl text-sm text-muted-foreground">{rule.description}</p>}
                </div>
                {canManage && <RuleActions rule={rule} />}
            </div>

            <div className="grid gap-4 xl:grid-cols-3">
                <SettingsPanel rule={rule} />
                <TrafficCard period={period} onPeriodChange={setPeriod} ruleId={rule.id} className="xl:col-span-2" />
            </div>
            <div className="grid gap-4 xl:grid-cols-2">
                <OffendersPanel ruleId={rule.id} />
                <ExemptionsPanel ruleId={rule.id} exemptions={exemptions} />
            </div>
            <HistoryPanel ruleId={rule.id} />
        </section>
    );
}

export default RulePage;

/** A manager's buttons: edit, duplicate, enable or disable, promote a dry-run rule, and delete (SPEC-022). */
function RuleActions({ rule }: { rule: Rule }) {
    const [, navigate] = useLocation();
    const toggle = useConfirmedChange([['rules']]);
    const promote = useConfirmedChange([['rules']]);
    const remove = useConfirmedChange([['rules', 'list'], ['rules', 'counts']], { onSuccess: () => navigate('/rules') });

    return (
        <div className="grid justify-items-end gap-2">
            <div className="flex flex-wrap justify-end gap-2">
                <Button variant="outline" asChild>
                    <Link href={`/rules/${rule.id}/edit`}>
                        <Pencil aria-hidden />
                        Edit
                    </Link>
                </Button>
                <Button variant="outline" asChild>
                    <Link href={`/rules/new?from=${rule.id}`}>
                        <Copy aria-hidden />
                        Duplicate
                    </Link>
                </Button>
                <Button variant="outline" disabled={toggle.pending} onClick={() => toggle.send({ method: 'PATCH', path: `rules/${rule.id}`, body: { enabled: !rule.enabled } })}>
                    <Power aria-hidden />
                    {rule.enabled ? 'Disable' : 'Enable'}
                </Button>
                {rule.mode === 'dry_run' && <PromoteDialog rule={rule} change={promote} />}
                <DeleteDialog rule={rule} change={remove} />
            </div>
            {[toggle, promote, remove].map((change, index) => (
                <ChangeOutcome key={index} change={change} />
            ))}
            <ConfirmDialog change={toggle} />
            <ConfirmDialog change={promote} />
        </div>
    );
}

/** Promote: the rule's last 24 hours as evidence, then enforce it (SPEC-022 decision 2). */
function PromoteDialog({ rule, change }: { rule: Rule; change: ConfirmedChange }) {
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger asChild>
                <Button disabled={change.pending}>
                    <ShieldCheck aria-hidden />
                    Promote
                </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Promote {rule.name} to enforcing</AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <div className="grid gap-2">{open && <LastDay ruleId={rule.id} />}</div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction onClick={() => change.send({ method: 'PATCH', path: `rules/${rule.id}`, body: { mode: 'enforce' } })}>Enforce</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/** What a dry-run rule would have done in the last 24 hours, from the hourly table. */
function LastDay({ ruleId }: { ruleId: number }) {
    const metrics = useMetrics('day');

    if (metrics.isError) {
        return <QueryError error={metrics.error} />;
    }

    if (metrics.data === undefined) {
        return <Skeleton className="h-5 w-full" />;
    }

    const sums = totals({ ...metrics.data, rules: metrics.data.rules.filter((rule) => rule.id === ruleId) });

    return (
        <>
            <p>
                {sums.hits === 0
                    ? 'In the last 24 hours it checked no request, so there is no evidence of what it would block.'
                    : `In the last 24 hours it checked ${formatCount(sums.hits)} requests and would have blocked ${formatCount(sums.wouldBlocks)} of them (${formatShare(sums.wouldBlocks, sums.hits)}).`}
            </p>
            <p>Once enforcing, it answers 429 to those requests.</p>
        </>
    );
}

/** Delete: asks first, always, since it cannot be undone, then sends confirm (SPEC-022 decision 6). */
function DeleteDialog({ rule, change }: { rule: Rule; change: ConfirmedChange }) {
    const limits = rule.enabled && rule.mode === 'enforce';

    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>
                <Button variant="destructive" disabled={change.pending}>
                    <Trash2 aria-hidden />
                    Delete
                </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    {/* The trailing space keeps the title and the description apart when read as one. */}
                    <AlertDialogTitle>Delete {rule.name}? </AlertDialogTitle>
                    <AlertDialogDescription>
                        Its exemptions are deleted with it.{limits ? ' It stops limiting requests.' : ''} This cannot be undone: the audit log keeps its settings.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction className={buttonVariants({ variant: 'destructive' })} onClick={() => change.send({ method: 'DELETE', path: `rules/${rule.id}`, body: { confirm: true } })}>
                        Delete
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/** A card that screen readers list as a region, named by its heading. */
function Panel({ title, children, className }: { title: string; children: ReactNode; className?: string }) {
    const id = useId();

    return (
        <Card role="region" aria-labelledby={id} className={className}>
            <CardHeader>
                <CardTitle>
                    <h2 id={id}>{title}</h2>
                </CardTitle>
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

function SettingsPanel({ rule }: { rule: Rule }) {
    const rows: [string, ReactNode][] = [
        ['Applies to', `${methodLabel(rule.method)} ${rule.pattern}`],
        ['Per', scopeLabel(rule)],
        ['Limit', limitLabel(rule)],
        ['Priority', formatCount(rule.priority)],
        ['When the counters fail', rule.on_failure === 'open' ? 'Let requests through' : 'Refuse requests'],
        ...(rule.scope === 'user' || rule.scope === 'api_token' || rule.scope === 'custom'
            ? [['Without an identity', rule.on_missing_identity === 'ip' ? 'Count per IP instead' : 'Skip the rule'] as [string, ReactNode]]
            : []),
        ['429 body', rule.response_override === null ? 'The default' : <code className="font-mono text-xs break-all">{JSON.stringify(rule.response_override)}</code>],
        ['Created', rule.created_at === null ? '—' : <Time value={rule.created_at} />],
        ['Last changed', rule.updated_at === null ? '—' : <Time value={rule.updated_at} />],
    ];

    return (
        <Panel title="Settings">
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                {rows.map(([label, value]) => (
                    <div key={label} className="contents">
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd>{value}</dd>
                    </div>
                ))}
            </dl>
        </Panel>
    );
}

function OffendersPanel({ ruleId }: { ruleId: number }) {
    return (
        <Panel title="Offenders">
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
        </Panel>
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

function ExemptionsPanel({ ruleId, exemptions }: { ruleId: number; exemptions: Exemption[] }) {
    return (
        <Panel title="Exemptions">
            <div className="grid gap-3">
                {exemptions.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No exemption names this rule.</p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Who</TableHead>
                                <TableHead>Reason</TableHead>
                                <TableHead>Ends</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {exemptions.map((exemption) => (
                                <TableRow key={exemption.id} className={exemption.active ? undefined : 'text-muted-foreground'}>
                                    <TableCell>
                                        <ExemptionWho exemption={exemption} />
                                    </TableCell>
                                    <TableCell className="max-w-48 truncate">{exemption.reason ?? '—'}</TableCell>
                                    <TableCell>{exemption.expires_at === null ? 'Never' : <Time value={exemption.expires_at} />}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
                <Link href={`/exemptions?rule=${ruleId}`} className="text-sm underline underline-offset-4">
                    See its exemptions
                </Link>
            </div>
        </Panel>
    );
}

const ACTIONS: Record<string, string> = {
    'rule.create': 'Created',
    'rule.update': 'Changed',
    'rule.enable': 'Enabled',
    'rule.disable': 'Disabled',
    'rule.delete': 'Deleted',
};

function HistoryPanel({ ruleId }: { ruleId: number }) {
    const target = `rule:${ruleId}`;
    const audit = useAuditOf(target);

    return (
        <Panel title="History">
            {audit.isError && <QueryError error={audit.error} />}
            {audit.data === undefined && !audit.isError && <Skeleton className="h-24 w-full" />}
            {audit.data !== undefined && (
                <div className="grid gap-3">
                    {audit.data.data.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No change recorded.</p>
                    ) : (
                        <ol className="grid gap-3">
                            {audit.data.data.map((entry) => (
                                <li key={entry.id} className="grid gap-1 border-l-2 pl-3 text-sm">
                                    <p className="flex flex-wrap items-baseline gap-x-2">
                                        <span data-action className="font-medium">
                                            {ACTIONS[entry.action] ?? entry.action}
                                        </span>
                                        <span className="text-muted-foreground">by</span>
                                        <code className="font-mono text-xs">{entry.actor}</code>
                                        {entry.created_at !== null && (
                                            <span className="text-muted-foreground">
                                                <Time value={entry.created_at} />
                                            </span>
                                        )}
                                    </p>
                                    {entry.action === 'rule.update' && <Changes entry={entry} />}
                                </li>
                            ))}
                        </ol>
                    )}
                    {audit.data.next_cursor !== null && (
                        <Link href={`/audit?target=${encodeURIComponent(target)}`} className="text-sm underline underline-offset-4">
                            Older changes are in the audit log
                        </Link>
                    )}
                </div>
            )}
        </Panel>
    );
}

/** What a change did, field by field: "max_attempts: 5 → 10". */
function Changes({ entry }: { entry: AuditEntry }) {
    const after = entry.after ?? {};
    const before = entry.before ?? {};

    return (
        <div className="grid gap-0.5 text-xs text-muted-foreground">
            {Object.keys(after).map((field) => (
                <code key={field} className="font-mono">
                    {field}: {show(before[field])} → {show(after[field])}
                </code>
            ))}
        </div>
    );
}

function show(value: unknown): string {
    return value === null || value === undefined ? 'none' : typeof value === 'string' ? value : JSON.stringify(value);
}
