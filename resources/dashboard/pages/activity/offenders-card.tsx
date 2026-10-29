import { useId, useState } from 'react';
import { OffendersByHour } from '@/components/offenders';
import { QueryError } from '@/components/query-error';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Skeleton } from '@/components/ui/skeleton';
import { useRules } from '@/lib/rules';

/** Every rule's first page, in evaluation order: the card's choices. */
const FIRST_RULES = { search: '', enabled: '', mode: '', page: 1 } as const;

/**
 * Any rule's top offenders, this hour or the last (SPEC-024 AC-6): the first rule's
 * until another is chosen. They stay identity hashes (decision 4).
 */
export function OffendersCard() {
    const rules = useRules(FIRST_RULES);
    const [chosen, setChosen] = useState('');
    const id = useId();
    const list = rules.data?.data;
    const ruleId = chosen === '' ? list?.[0]?.id : Number(chosen);

    return (
        <Card role="region" aria-labelledby={id}>
            <CardHeader>
                <CardTitle>
                    <h2 id={id}>Offenders</h2>
                </CardTitle>
                <CardDescription>The clients a rule blocked most, by identity hash. The live feed above shows their IPs for the last hour.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4">
                {rules.isError && <QueryError error={rules.error} />}
                {list === undefined && !rules.isError && <Skeleton className="h-24 w-full" />}
                {list !== undefined && list.length === 0 && <p className="text-sm text-muted-foreground">There is no rule yet.</p>}
                {list !== undefined && ruleId !== undefined && (
                    <>
                        <div className="grid max-w-64 gap-1.5">
                            <Label htmlFor={`${id}-rule`}>Rule</Label>
                            <NativeSelect id={`${id}-rule`} value={String(ruleId)} onChange={(event) => setChosen(event.target.value)}>
                                {list.map((rule) => (
                                    <option key={rule.id} value={String(rule.id)}>
                                        {rule.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        {/* Keyed, so a new rule starts on this hour. */}
                        <OffendersByHour key={ruleId} ruleId={ruleId} mode={list.find((rule) => rule.id === ruleId)?.mode} />
                    </>
                )}
            </CardContent>
        </Card>
    );
}

export default OffendersCard;
