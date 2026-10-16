import { type Rule, useRuleName } from '@/lib/rules';

/** A rule's name from the first rules, or asked for by its id once they load without it (decision 6). */
export function RuleName({ id, rules }: { id: number; rules: Rule[] | undefined }) {
    const known = rules?.find((rule) => rule.id === id);
    const asked = useRuleName(id, rules !== undefined && known === undefined);

    return known?.name ?? asked.data ?? `Rule #${id}`;
}
