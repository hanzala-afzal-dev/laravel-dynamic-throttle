import { useQuery } from '@tanstack/react-query';
import { type ReactNode, useId, useState } from 'react';
import { Link, useLocation, useSearch } from 'wouter';
import { ChangeOutcome, ConfirmDialog } from '@/components/confirm-dialog';
import { QueryError } from '@/components/query-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { api } from '@/lib/api';
import { useConfirmedChange } from '@/lib/confirmed-change';
import { useMe } from '@/lib/queries';
import type { Rule } from '@/lib/rules';
import { BLANK, type RuleDraft, TEMPLATES } from '@/pages/rules/templates';

const UNITS = [
    { value: 'seconds', seconds: 1 },
    { value: 'minutes', seconds: 60 },
    { value: 'hours', seconds: 3_600 },
    { value: 'days', seconds: 86_400 },
] as const;

type Unit = (typeof UNITS)[number]['value'];

/** The form's fields, as text where the person types them. */
type Values = {
    name: string;
    description: string;
    method: string;
    pattern: string;
    scope: Rule['scope'];
    scope_parameter: string;
    max_attempts: string;
    window: string;
    unit: Unit;
    priority: string;
    mode: Rule['mode'];
    on_failure: Rule['on_failure'];
    on_missing_identity: Rule['on_missing_identity'];
    response_override: string;
    enabled: boolean;
};

const JSON_ERROR = 'The 429 body must be a JSON object, such as {"message": "Too many requests."}.';

/**
 * A new rule, from a template, blank or a duplicate, or a rule's edit (SPEC-022 AC-5
 * to AC-7). A new rule starts in dry-run (decision 2); an edit sends only the fields
 * that changed (decision 5).
 */
export function RuleForm({ id }: { id?: number }) {
    const from = new URLSearchParams(useSearch()).get('from');
    const sourceId = id ?? (from !== null && /^[1-9]\d*$/.test(from) ? Number(from) : undefined);
    const me = useMe();
    const source = useQuery({
        queryKey: ['rules', 'one', sourceId],
        queryFn: () => api<{ rule: Rule }>('GET', `rules/${sourceId}`),
        enabled: sourceId !== undefined,
    });

    if (me.data === undefined || (sourceId !== undefined && source.data === undefined && !source.isError)) {
        return <Skeleton className="h-96 w-full" />;
    }

    if (!me.data.can.manage) {
        return <p className="text-sm text-muted-foreground">Only managers can create or change rules.</p>;
    }

    // A refetch that fails later keeps the form, and what was typed in it.
    if (source.isError && source.data === undefined) {
        return <QueryError error={source.error} />;
    }

    const rule = source.data?.rule;

    if (id !== undefined && rule !== undefined) {
        return <Editor key={rule.id} initial={valuesOf(rule)} rule={rule} />;
    }

    return <Editor key={rule?.id ?? 'new'} initial={valuesOf(rule === undefined ? BLANK : { ...rule, name: `Copy of ${rule.name}`, mode: 'dry_run' })} duplicateOf={rule} />;
}

export default RuleForm;

function Editor({ initial, rule, duplicateOf }: { initial: Values; rule?: Rule; duplicateOf?: Rule }) {
    const [values, setValues] = useState(initial);
    // The rule as the form opened it, which a refetch never moves, so an edit sends only what this person changed (AC-12).
    const [before] = useState(() => bodyOf(initial) ?? { ...bodyOf({ ...initial, response_override: '' }), response_override: initial.response_override });
    const [jsonError, setJsonError] = useState<string | null>(null);
    const [unchanged, setUnchanged] = useState(false);
    const [, navigate] = useLocation();
    const change = useConfirmedChange([['rules']], {
        onSuccess: (answer) => {
            const saved = typeof answer === 'object' && answer !== null && 'rule' in answer ? (answer.rule as Partial<Rule> | null) : null;
            navigate(`/rules/${saved?.id ?? rule?.id ?? ''}`);
        },
    });
    const id = useId();
    const set = <K extends keyof Values>(key: K, value: Values[K]) => {
        setUnchanged(false);
        setValues((current) => ({ ...current, [key]: value }));
    };
    const error = (field: string) => (field === 'response_override' && jsonError !== null ? jsonError : change.fields[field]?.[0]);

    const submit = () => {
        const body = bodyOf(values);

        if (body === null) {
            setJsonError(JSON_ERROR);

            return;
        }

        setJsonError(null);

        if (rule === undefined) {
            change.send({ method: 'POST', path: 'rules', body });

            return;
        }

        const changed = Object.fromEntries(Object.entries(body).filter(([key, value]) => JSON.stringify(value) !== JSON.stringify(before[key])));

        if (Object.keys(changed).length === 0) {
            setUnchanged(true);

            return;
        }

        change.send({ method: 'PATCH', path: `rules/${rule.id}`, body: changed });
    };

    const field = (name: string, label: string, control: (props: FieldProps) => ReactNode, hint?: string) => (
        <Field id={`${id}-${name}`} label={label} hint={hint} error={error(name)}>
            {control}
        </Field>
    );

    return (
        <section className="grid grid-cols-1 gap-6">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">{rule === undefined ? 'New rule' : `Edit ${rule.name}`}</h1>
                <p className="mt-1 text-sm text-muted-foreground">
                    {rule !== undefined
                        ? 'Saving sends only the fields you changed.'
                        : duplicateOf !== undefined
                          ? `A copy of ${duplicateOf.name}, in dry-run until you promote it.`
                          : 'A new rule starts in dry-run: it counts what it would block, and blocks nothing until you promote it.'}
                </p>
            </div>
            <form
                className="grid gap-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                {rule === undefined && duplicateOf === undefined && (
                    <div className="grid max-w-xs gap-1.5">
                        <Label htmlFor={`${id}-template`}>Template</Label>
                        <NativeSelect
                            id={`${id}-template`}
                            defaultValue=""
                            onChange={(event) => {
                                const template = TEMPLATES.find((candidate) => candidate.label === event.target.value || candidate.key === event.target.value);

                                if (template !== undefined) {
                                    setUnchanged(false);
                                    setValues(valuesOf(template.draft));
                                }
                            }}
                        >
                            <option value="" disabled>
                                Start from…
                            </option>
                            {TEMPLATES.map((template) => (
                                <option key={template.key} value={template.label}>
                                    {template.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                )}

                <Section title="The rule">
                    {field('name', 'Name', (props) => <Input {...props} value={values.name} onChange={(event) => set('name', event.target.value)} />)}
                    {field('description', 'Description', (props) => <Textarea {...props} value={values.description} onChange={(event) => set('description', event.target.value)} />, 'Optional: why the rule exists.')}
                </Section>

                <Section title="What it matches">
                    {field('method', 'Method', (props) => (
                        <NativeSelect {...props} value={values.method} onChange={(event) => set('method', event.target.value)}>
                            {['*', 'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'].map((method) => (
                                <option key={method} value={method}>
                                    {method === '*' ? 'Any' : method}
                                </option>
                            ))}
                        </NativeSelect>
                    ))}
                    {field(
                        'pattern',
                        'Pattern',
                        (props) => <Input {...props} className="font-mono" value={values.pattern} onChange={(event) => set('pattern', event.target.value)} />,
                        'A path, with * as a wildcard: login, api/*, api/*/export.',
                    )}
                </Section>

                <Section title="Who it counts">
                    {field('scope', 'Per', (props) => (
                        <NativeSelect {...props} value={values.scope} onChange={(event) => set('scope', event.target.value as Rule['scope'])}>
                            <option value="ip">IP</option>
                            <option value="user">User</option>
                            <option value="api_token">API token</option>
                            <option value="header">Header</option>
                            <option value="global">Everyone</option>
                            <option value="custom">Custom</option>
                        </NativeSelect>
                    ))}
                    {values.scope === 'header' &&
                        field('scope_parameter', 'Header name', (props) => <Input {...props} value={values.scope_parameter} onChange={(event) => set('scope_parameter', event.target.value)} />, 'Such as X-Tenant.')}
                    {values.scope === 'custom' &&
                        field(
                            'scope_parameter',
                            'Registered scope',
                            (props) => <Input {...props} value={values.scope_parameter} onChange={(event) => set('scope_parameter', event.target.value)} />,
                            'A scope your app registers with DynamicThrottle::scope().',
                        )}
                    {(values.scope === 'user' || values.scope === 'api_token' || values.scope === 'custom') &&
                        field('on_missing_identity', 'Without an identity', (props) => (
                            <NativeSelect {...props} value={values.on_missing_identity} onChange={(event) => set('on_missing_identity', event.target.value as Rule['on_missing_identity'])}>
                                <option value="ip">Count per IP instead</option>
                                <option value="skip">Skip the rule</option>
                            </NativeSelect>
                        ))}
                </Section>

                <Section title="The limit">
                    <div className="flex flex-wrap items-start gap-3">
                        {field('max_attempts', 'Attempts', (props) => <Input {...props} className="w-32" inputMode="numeric" value={values.max_attempts} onChange={(event) => set('max_attempts', event.target.value)} />)}
                        {field('window_seconds', 'Window', (props) => <Input {...props} className="w-24" inputMode="decimal" value={values.window} onChange={(event) => set('window', event.target.value)} />, 'At most a day.')}
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${id}-unit`}>Window unit</Label>
                            <NativeSelect id={`${id}-unit`} value={values.unit} onChange={(event) => set('unit', event.target.value as Unit)}>
                                {UNITS.map((unit) => (
                                    <option key={unit.value} value={unit.value}>
                                        {unit.value}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    </div>
                </Section>

                <Section title="How it acts">
                    {field('mode', 'Mode', (props) => (
                        <NativeSelect {...props} value={values.mode} onChange={(event) => set('mode', event.target.value as Rule['mode'])}>
                            <option value="dry_run">Dry-run</option>
                            <option value="enforce">Enforcing</option>
                        </NativeSelect>
                    ), 'Dry-run counts what it would block; enforcing answers 429.')}
                    {field('priority', 'Priority', (props) => <Input {...props} className="w-32" inputMode="numeric" value={values.priority} onChange={(event) => set('priority', event.target.value)} />, 'Lower runs first.')}
                    {field('on_failure', 'When the counters fail', (props) => (
                        <NativeSelect {...props} value={values.on_failure} onChange={(event) => set('on_failure', event.target.value as Rule['on_failure'])}>
                            <option value="open">Let requests through</option>
                            <option value="closed">Refuse requests</option>
                        </NativeSelect>
                    ))}
                    <div className="flex items-center gap-2">
                        <Switch id={`${id}-enabled`} checked={values.enabled} onCheckedChange={(enabled) => set('enabled', enabled)} />
                        <Label htmlFor={`${id}-enabled`}>Enabled</Label>
                    </div>
                    {error('enabled') !== undefined && <p className="text-sm text-destructive">{error('enabled')}</p>}
                </Section>

                <Section title="The 429 response">
                    {field(
                        'response_override',
                        '429 body',
                        (props) => (
                            <Textarea {...props} className="font-mono" placeholder='{"message": "Too many requests."}' value={values.response_override} onChange={(event) => set('response_override', event.target.value)} />
                        ),
                        'Optional JSON. Empty keeps the package\'s default body.',
                    )}
                </Section>

                <div className="flex flex-wrap items-center gap-3">
                    <Button type="submit" disabled={change.pending}>
                        {rule === undefined ? 'Create rule' : 'Save changes'}
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={rule === undefined ? '/rules' : `/rules/${rule.id}`}>Cancel</Link>
                    </Button>
                    {unchanged && <p className="text-sm text-muted-foreground">Nothing changed.</p>}
                    {Object.keys(change.fields).length === 0 && <ChangeOutcome change={change} />}
                </div>
            </form>
            <ConfirmDialog change={change} />
        </section>
    );
}

type FieldProps = { id: string; 'aria-describedby'?: string; 'aria-invalid'?: boolean };

function Field({ id, label, hint, error, children }: { id: string; label: string; hint?: string; error?: string; children: (props: FieldProps) => ReactNode }) {
    // The error replaces the hint, so the field points at whichever shows.
    const described = error !== undefined ? `${id}-error` : hint !== undefined ? `${id}-hint` : undefined;

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children({ id, 'aria-describedby': described, 'aria-invalid': error === undefined ? undefined : true })}
            {error !== undefined && (
                <p id={`${id}-error`} className="text-sm text-destructive">
                    {error}
                </p>
            )}
            {hint !== undefined && error === undefined && (
                <p id={`${id}-hint`} className="text-xs text-muted-foreground">
                    {hint}
                </p>
            )}
        </div>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    <h2>{title}</h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="grid max-w-2xl gap-4">{children}</CardContent>
        </Card>
    );
}

/** A rule or a draft as the form shows it, the window in its largest whole unit. */
function valuesOf(rule: RuleDraft): Values {
    const unit = [...UNITS].reverse().find((candidate) => rule.window_seconds % candidate.seconds === 0) ?? UNITS[0];

    return {
        name: rule.name,
        description: rule.description ?? '',
        method: rule.method,
        pattern: rule.pattern,
        scope: rule.scope,
        scope_parameter: rule.scope_parameter ?? '',
        max_attempts: String(rule.max_attempts),
        window: String(rule.window_seconds / unit.seconds),
        unit: unit.value,
        priority: String(rule.priority),
        mode: rule.mode,
        on_failure: rule.on_failure,
        on_missing_identity: rule.on_missing_identity,
        // An empty object may come back as [] from an older answer (AC-13).
        response_override: rule.response_override === null ? '' : Array.isArray(rule.response_override) && rule.response_override.length === 0 ? '{}' : JSON.stringify(rule.response_override),
        enabled: rule.enabled,
    };
}

/** The values as the API takes them, or null when the 429 body is not a JSON object. */
function bodyOf(values: Values): Record<string, unknown> | null {
    let override: unknown = null;

    if (values.response_override.trim() !== '') {
        try {
            override = JSON.parse(values.response_override);
        } catch {
            return null;
        }

        if (typeof override !== 'object' || override === null || Array.isArray(override)) {
            return null;
        }
    }

    const unitSeconds = UNITS.find((unit) => unit.value === values.unit)?.seconds ?? 1;
    const number = (text: string) => (text.trim() === '' ? null : Number(text));
    const window = number(values.window);

    return {
        name: values.name,
        description: values.description.trim() === '' ? null : values.description,
        method: values.method,
        pattern: values.pattern,
        scope: values.scope,
        scope_parameter: values.scope === 'header' || values.scope === 'custom' ? values.scope_parameter : null,
        max_attempts: number(values.max_attempts),
        // 1.1 hours is 3,960 seconds, not 3,960.0000000000005 (AC-18).
        window_seconds: window === null ? null : Math.round(window * unitSeconds),
        priority: number(values.priority),
        mode: values.mode,
        on_failure: values.on_failure,
        on_missing_identity: values.on_missing_identity,
        response_override: override,
        enabled: values.enabled,
    };
}
