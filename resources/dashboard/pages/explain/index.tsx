import { useMutation } from '@tanstack/react-query';
import { type ReactNode, useId, useState } from 'react';
import { Link } from 'wouter';
import { QueryError } from '@/components/query-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { api, ApiError } from '@/lib/api';
import { counterWords, type ExplainForm, type Explanation, explainBody, limitWords, METHODS, statusWords, UNTOUCHED } from '@/lib/explain';
import { Time } from '@/lib/time';

/**
 * Explain (SPEC-026): what the middleware would do with a request, and why, without
 * counting it. The form's values go in the body, never the URL (decision 1).
 */
export function ExplainPage() {
    const explain = useMutation({ mutationFn: (form: ExplainForm) => api<Explanation>('POST', 'explain', explainBody(form)) });
    const fields = explain.error instanceof ApiError ? explain.error.fields : {};
    const requestError = fields.request?.[0];
    const fieldError = Object.keys(fields).some((field) => field !== 'request');

    return (
        <section className="grid gap-6">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">Explain</h1>
                <p className="mt-1 text-sm text-muted-foreground">What the rules would do with a request, and why. Nothing is counted or changed.</p>
            </div>

            <RequestForm onExplain={(form) => explain.mutate(form)} pending={explain.isPending} fields={fields} />

            {requestError !== undefined && <p className="text-sm text-destructive">{requestError}</p>}
            {explain.isError && requestError === undefined && !fieldError && <QueryError error={explain.error} />}
            {explain.data !== undefined && <Result explanation={explain.data} />}
        </section>
    );
}

export default ExplainPage;

function RequestForm({ onExplain, pending, fields }: { onExplain: (form: ExplainForm) => void; pending: boolean; fields: Record<string, string[]> }) {
    const [form, setForm] = useState<ExplainForm>({ method: 'GET', path: '', ip: '', user: '', guard: '', headers: '' });
    const id = useId();
    const set = (key: keyof ExplainForm, value: string) => setForm((current) => ({ ...current, [key]: value }));
    const error = (field: string) => (fields[field] ?? Object.entries(fields).find(([key]) => key.startsWith(`${field}.`))?.[1])?.[0];

    return (
        <Card>
            <CardContent>
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        onExplain(form);
                    }}
                >
                    <div className="grid gap-4 md:grid-cols-[8rem_1fr]">
                        <Field id={`${id}-method`} label="Method" error={error('method')}>
                            {(props) => (
                                <NativeSelect {...props} value={form.method} onChange={(event) => set('method', event.target.value)}>
                                    {METHODS.map((method) => (
                                        <option key={method} value={method}>
                                            {method}
                                        </option>
                                    ))}
                                </NativeSelect>
                            )}
                        </Field>
                        <Field id={`${id}-path`} label="Path" error={error('path')}>
                            {(props) => <Input {...props} className="font-mono" placeholder="/api/orders" required maxLength={2048} value={form.path} onChange={(event) => set('path', event.target.value)} />}
                        </Field>
                    </div>
                    <div className="grid gap-4 md:grid-cols-3">
                        <Field id={`${id}-ip`} label="IP" hint="As your app sees it, after its trusted proxies. 127.0.0.1 when empty." error={error('ip')}>
                            {(props) => <Input {...props} className="font-mono" placeholder="127.0.0.1" value={form.ip} onChange={(event) => set('ip', event.target.value)} />}
                        </Field>
                        <Field id={`${id}-user`} label="User id" hint="Looked up by the guard below, or the default guard." error={error('user')}>
                            {(props) => <Input {...props} className="font-mono" maxLength={255} value={form.user} onChange={(event) => set('user', event.target.value)} />}
                        </Field>
                        <Field id={`${id}-guard`} label="Guard" hint="Optional; needs a user id." error={error('guard')}>
                            {(props) => <Input {...props} className="font-mono" maxLength={64} value={form.guard} onChange={(event) => set('guard', event.target.value)} />}
                        </Field>
                    </div>
                    <Field id={`${id}-headers`} label="Headers" hint="One a line, as Name: value, up to 20. They go in the request's body, never the URL." error={error('headers')}>
                        {(props) => <Textarea {...props} className="font-mono" rows={3} placeholder="Authorization: Bearer …" value={form.headers} onChange={(event) => set('headers', event.target.value)} />}
                    </Field>
                    <div>
                        <Button type="submit" disabled={pending}>
                            Explain
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

type FieldProps = { id: string; 'aria-describedby'?: string; 'aria-invalid'?: boolean };

/** A label, its control, and the API's error or else the hint, which the control points at. */
function Field({ id, label, hint, error, children }: { id: string; label: string; hint?: string; error?: string; children: (props: FieldProps) => ReactNode }) {
    const described = error !== undefined ? `${id}-error` : hint !== undefined ? `${id}-hint` : undefined;

    return (
        <div className="grid content-start gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children({ id, 'aria-describedby': described, 'aria-invalid': error === undefined ? undefined : true })}
            {error !== undefined ? (
                <p id={`${id}-error`} className="text-sm text-destructive">
                    {error}
                </p>
            ) : (
                hint !== undefined && (
                    <p id={`${id}-hint`} className="text-xs text-muted-foreground">
                        {hint}
                    </p>
                )
            )}
        </div>
    );
}

function Result({ explanation }: { explanation: Explanation }) {
    return (
        <div className="grid gap-6">
            <Panel title="Decision">
                <Decision explanation={explanation} />
            </Panel>
            <Panel title="Matching rules">
                <Rules explanation={explanation} />
            </Panel>
            <div className="grid items-start gap-6 md:grid-cols-2">
                <Panel title="Route">
                    <Route route={explanation.route} />
                </Panel>
                <Panel title="Context">
                    <Context explanation={explanation} />
                </Panel>
            </div>
        </div>
    );
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
    const id = useId();

    return (
        <Card role="region" aria-labelledby={id}>
            <CardHeader>
                <CardTitle>
                    <h2 id={id}>{title}</h2>
                </CardTitle>
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

/** The outcome in words (decision 3), naming and linking the rule that decided it. */
function Decision({ explanation }: { explanation: Explanation }) {
    const { decision } = explanation;
    const ruleId = decision.rule;
    const name = ruleId === null ? null : (explanation.rules.find((rule) => rule.id === ruleId)?.name ?? `rule #${ruleId}`);
    const rule = ruleId === null ? null : (
        <Link href={`/rules/${ruleId}`} className="font-medium underline underline-offset-4">
            {name}
        </Link>
    );
    const headers = Object.entries(decision.headers);

    const words: Record<Explanation['decision']['outcome'], ReactNode> = {
        allowed: 'Allowed: the next such request goes through.',
        rejected: <>Blocked with a 429 by {rule}.</>,
        unavailable: <>A 503: {rule} could not be checked, and it fails closed.</>,
        failed: <>Allowed anyway: {rule} could not be checked, and it fails open.</>,
        unknown: 'Unknown: the counters could not be read.',
        untouched: `Untouched: ${UNTOUCHED[decision.reason ?? ''] ?? decision.reason ?? ''}`,
    };

    return (
        <div className="grid gap-4">
            <p className="text-lg font-medium" title={decision.reason === null ? decision.outcome : `${decision.outcome}: ${decision.reason}`}>
                {words[decision.outcome]}
            </p>
            {headers.length > 0 && (
                <Table className="w-auto">
                    <caption className="sr-only">Headers</caption>
                    <TableBody>
                        {headers.map(([header, value]) => (
                            <TableRow key={header}>
                                <th scope="row" className="p-2 pr-6 text-left font-mono text-xs font-normal text-muted-foreground">
                                    {header}
                                </th>
                                <TableCell className="font-mono text-xs tabular-nums">{value}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </div>
    );
}

/** The matched rules, in the order they run. */
function Rules({ explanation }: { explanation: Explanation }) {
    if (explanation.rules.length === 0) {
        return <p className="text-sm text-muted-foreground">No rule matches this request.</p>;
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Rule</TableHead>
                    <TableHead>Matches</TableHead>
                    <TableHead>Mode</TableHead>
                    <TableHead>Limit</TableHead>
                    <TableHead>Scope</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Identity</TableHead>
                    <TableHead>Counter</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {explanation.rules.map((rule) => (
                    <TableRow key={rule.id}>
                        <TableCell>
                            <Link href={`/rules/${rule.id}`} className="font-medium underline-offset-4 hover:underline">
                                {rule.name}
                            </Link>
                        </TableCell>
                        <TableCell className="font-mono text-xs">{`${rule.method} ${rule.pattern}`}</TableCell>
                        <TableCell>{rule.mode === 'dry_run' ? 'Dry run' : 'Enforce'}</TableCell>
                        <TableCell className="whitespace-nowrap">{limitWords(rule)}</TableCell>
                        <TableCell className="font-mono text-xs">{rule.scope_parameter === null ? rule.scope : `${rule.scope}:${rule.scope_parameter}`}</TableCell>
                        <TableCell className="max-w-64 whitespace-normal" title={rule.status}>
                            {statusWords(rule)}
                        </TableCell>
                        <TableCell className="max-w-56 truncate font-mono text-xs" title={rule.hash ?? undefined}>
                            {rule.identity ?? '—'}
                        </TableCell>
                        <TableCell className="whitespace-nowrap">{rule.counter === null ? '—' : counterWords(rule.counter)}</TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/** The route, its middleware in running order, and where dynamic.throttle runs. */
function Route({ route }: { route: Explanation['route'] }) {
    if (route === null) {
        return <p className="text-sm text-muted-foreground">No route matches this request.</p>;
    }

    const where = !route.throttled
        ? 'This route does not run dynamic.throttle.'
        : route.after_auth
          ? 'dynamic.throttle runs after authentication.'
          : 'dynamic.throttle runs before authentication, so user rules count a guest.';

    return (
        <div className="grid gap-3 text-sm">
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                <dt className="text-muted-foreground">URI</dt>
                <dd className="font-mono text-xs break-all">{route.uri}</dd>
                <dt className="text-muted-foreground">Name</dt>
                <dd className="font-mono text-xs">{route.name ?? '—'}</dd>
            </dl>
            <div className="grid gap-1">
                <p className="text-muted-foreground">Middleware, in running order</p>
                <ol className="list-decimal pl-6 font-mono text-xs">
                    {route.middleware.map((middleware, index) => (
                        <li key={index} className="break-all">
                            {middleware}
                        </li>
                    ))}
                </ol>
            </div>
            <p>{where}</p>
        </div>
    );
}

/** The manifest, the emergency settings and the counters the explanation read. */
function Context({ explanation }: { explanation: Explanation }) {
    const { manifest, settings, counters } = explanation;
    const plural = (count: number, word: string) => `${count} ${word}${count === 1 ? '' : 's'}`;

    return (
        <ul className="grid gap-2 text-sm">
            <li>
                {manifest === null
                    ? 'No manifest: run php artisan dynamic-throttle:compile.'
                    : `Manifest version ${manifest.version}: ${plural(manifest.rules, 'rule')}, ${plural(manifest.exemptions, 'exemption')}.`}
            </li>
            <li>
                {settings.bypass === null ? (
                    'Bypass is off.'
                ) : settings.bypass.until === null ? (
                    'Bypass is on, with no end.'
                ) : (
                    <>
                        Bypass is on until <Time value={settings.bypass.until} />.
                    </>
                )}
            </li>
            <li>
                {settings.scale === null ? (
                    'The scale factor is off.'
                ) : settings.scale.until === null ? (
                    `Scale factor ${settings.scale.factor}, with no end.`
                ) : (
                    <>
                        Scale factor {settings.scale.factor} until <Time value={settings.scale.until} />.
                    </>
                )}
            </li>
            <li>{counters.read ? 'The counters were read.' : counters.error === null ? 'The counters were not read.' : `The counters could not be read: ${counters.error}`}</li>
        </ul>
    );
}
