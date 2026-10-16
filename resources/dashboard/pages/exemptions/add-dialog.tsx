import { Plus } from 'lucide-react';
import { type ReactNode, useId, useState } from 'react';
import { ChangeOutcome, ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { useConfirmedChange } from '@/lib/confirmed-change';
import type { Exemption } from '@/lib/exemptions';
import type { Rule } from '@/lib/rules';
import { RuleName } from '@/pages/exemptions/rule-name';

/** How long an exemption lasts, 1 day by default (decision 3). */
const LENGTHS = [
    { value: '1h', label: '1 hour', seconds: 3_600 },
    { value: '1d', label: '1 day', seconds: 86_400 },
    { value: '7d', label: '7 days', seconds: 604_800 },
    { value: '30d', label: '30 days', seconds: 2_592_000 },
    { value: 'never', label: 'No end', seconds: null },
] as const;

const VALUE_HINTS: Record<Exemption['subject'], string> = {
    ip: 'An IP address or a CIDR range, such as 203.0.113.0/24.',
    user: 'The user\'s id, or Class:id for another model.',
    custom: 'The value the scope gives, such as a tenant\'s id.',
};

/** The queries an exemption's change touches: the list, a rule's page, and the audit log. */
export const EXEMPTION_QUERIES = [['exemptions'], ['rules', 'one'], ['audit']];

/**
 * "Add exemption", for a manager: who, for which rule, for how long and why (SPEC-023
 * AC-4, AC-5). The end time is now plus the length, computed when saving.
 */
export function AddExemptionDialog({ rules, rule }: { rules: Rule[] | undefined; rule: string }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus aria-hidden />
                    Add exemption
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add an exemption</DialogTitle>
                    <DialogDescription>Its requests skip the rule, or every rule, until it ends.</DialogDescription>
                </DialogHeader>
                {/* Mounted while open, so each opening starts blank. */}
                <AddForm rules={rules} rule={rule} onAdded={() => setOpen(false)} />
            </DialogContent>
        </Dialog>
    );
}

function AddForm({ rules, rule, onAdded }: { rules: Rule[] | undefined; rule: string; onAdded: () => void }) {
    const [values, setValues] = useState({ subject: 'ip' as Exemption['subject'], scope: '', value: '', rule, length: '1d', reason: '' });
    const change = useConfirmedChange(EXEMPTION_QUERIES, { onSuccess: onAdded });
    const id = useId();
    const set = (key: keyof typeof values, value: string) => setValues((current) => ({ ...current, [key]: value }));
    const error = (field: string) => change.fields[field]?.[0];

    const submit = () => {
        const seconds = LENGTHS.find((length) => length.value === values.length)?.seconds ?? null;

        change.send({
            method: 'POST',
            path: 'exemptions',
            body: {
                subject: values.subject,
                subject_parameter: values.subject === 'custom' ? values.scope.trim() : null,
                value: values.value.trim(),
                rule_id: values.rule === '' ? null : Number(values.rule),
                expires_at: seconds === null ? null : new Date(Date.now() + seconds * 1_000).toISOString(),
                reason: values.reason.trim() === '' ? null : values.reason.trim(),
            },
        });
    };

    return (
        <form
            className="grid gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <Field id={`${id}-subject`} label="Who" error={error('subject')}>
                {(props) => (
                    <NativeSelect {...props} value={values.subject} onChange={(event) => set('subject', event.target.value)}>
                        <option value="ip">IP or range</option>
                        <option value="user">User</option>
                        <option value="custom">Custom</option>
                    </NativeSelect>
                )}
            </Field>
            {values.subject === 'custom' && (
                <Field id={`${id}-scope`} label="Scope name" hint="A scope your app registers with DynamicThrottle::scope()." error={error('subject_parameter')}>
                    {(props) => <Input {...props} value={values.scope} onChange={(event) => set('scope', event.target.value)} />}
                </Field>
            )}
            <Field id={`${id}-value`} label="Value" hint={VALUE_HINTS[values.subject]} error={error('value')}>
                {(props) => <Input {...props} className="font-mono" value={values.value} onChange={(event) => set('value', event.target.value)} />}
            </Field>
            <Field id={`${id}-rule`} label="Rule" error={error('rule_id')}>
                {(props) => (
                    <NativeSelect {...props} value={values.rule} onChange={(event) => set('rule', event.target.value)}>
                        <option value="">Every rule</option>
                        {rules?.map((candidate) => (
                            <option key={candidate.id} value={String(candidate.id)}>
                                {candidate.name}
                            </option>
                        ))}
                        {rule !== '' && rules?.some((candidate) => String(candidate.id) === rule) !== true && (
                            <option value={rule}>
                                <RuleName id={Number(rule)} rules={rules} />
                            </option>
                        )}
                    </NativeSelect>
                )}
            </Field>
            <Field id={`${id}-length`} label="Length" error={error('expires_at')}>
                {(props) => (
                    <NativeSelect {...props} value={values.length} onChange={(event) => set('length', event.target.value)}>
                        {LENGTHS.map((length) => (
                            <option key={length.value} value={length.value}>
                                {length.label}
                            </option>
                        ))}
                    </NativeSelect>
                )}
            </Field>
            <Field id={`${id}-reason`} label="Reason" hint="Optional: who asked, and why." error={error('reason')}>
                {(props) => <Input {...props} maxLength={255} value={values.reason} onChange={(event) => set('reason', event.target.value)} />}
            </Field>
            {Object.keys(change.fields).length === 0 && <ChangeOutcome change={change} />}
            <DialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="outline">
                        Cancel
                    </Button>
                </DialogClose>
                <Button type="submit" disabled={change.pending}>
                    Add exemption
                </Button>
            </DialogFooter>
            <ConfirmDialog change={change} />
        </form>
    );
}

type FieldProps = { id: string; 'aria-describedby'?: string; 'aria-invalid'?: boolean };

/** A label, its control, and the API's error or else the hint, which the control points at. */
function Field({ id, label, hint, error, children }: { id: string; label: string; hint?: string; error?: string; children: (props: FieldProps) => ReactNode }) {
    const described = error !== undefined ? `${id}-error` : hint !== undefined ? `${id}-hint` : undefined;

    return (
        <div className="grid gap-1.5">
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
