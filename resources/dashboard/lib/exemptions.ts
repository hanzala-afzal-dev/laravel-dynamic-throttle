import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

/** An exemption, as the API gives it (SPEC-017). */
export type Exemption = {
    id: number;
    rule_id: number | null;
    subject: 'ip' | 'user' | 'custom';
    subject_parameter: string | null;
    value: string;
    reason: string | null;
    expires_at: string | null;
    active: boolean;
    created_at: string | null;
    updated_at: string | null;
};

/** A page of exemptions, as Laravel's paginator gives it. */
export type ExemptionPage = { data: Exemption[]; current_page: number; last_page: number; per_page: number; total: number };

/**
 * The list's filters (SPEC-023). The state is active unless the URL says otherwise; the
 * rule is a rule's id, "none" for the exemptions on every rule, or "" for all.
 */
export type ExemptionFilters = { state: 'active' | 'expired' | 'all'; subject: '' | Exemption['subject']; rule: string; page: number };

/** The filters as the page's URL holds them, leaving out the defaults: state=expired&rule=3. */
export function pageQuery(filters: ExemptionFilters): string {
    return queryOf(filters, filters.state === 'active' ? '' : filters.state);
}

/** The filters as GET api/exemptions takes them: state=active&subject=ip. */
export function apiQuery(filters: ExemptionFilters): string {
    return queryOf(filters, filters.state === 'all' ? '' : filters.state);
}

function queryOf(filters: ExemptionFilters, state: string): string {
    const params = new URLSearchParams();
    const values: [string, string][] = [
        ['state', state],
        ['subject', filters.subject],
        ['rule', filters.rule],
    ];

    for (const [name, value] of values) {
        if (value !== '') {
            params.set(name, value);
        }
    }

    if (filters.page > 1) {
        params.set('page', String(filters.page));
    }

    return params.toString();
}

/** The filters a page's search string holds, ignoring a value the API would refuse. */
export function filtersOf(search: string): ExemptionFilters {
    const params = new URLSearchParams(search);
    const state = params.get('state');
    const subject = params.get('subject');
    const rule = params.get('rule') ?? '';
    const page = Number.parseInt(params.get('page') ?? '1', 10);

    return {
        state: state === 'expired' || state === 'all' ? state : 'active',
        subject: subject === 'ip' || subject === 'user' || subject === 'custom' ? subject : '',
        rule: rule === 'none' || /^[1-9]\d{0,17}$/.test(rule) ? rule : '',
        page: Number.isInteger(page) && page > 0 ? page : 1,
    };
}

export function useExemptions(filters: ExemptionFilters) {
    const query = apiQuery(filters);

    return useQuery({
        queryKey: ['exemptions', 'list', query],
        queryFn: () => api<ExemptionPage>('GET', query === '' ? 'exemptions' : `exemptions?${query}`),
        // The rows stay while the next page or filter loads.
        placeholderData: keepPreviousData,
    });
}

/**
 * Who an exemption lets through, as a kind and a value: "IP" and "203.0.113.0/24", "User"
 * and "42" for App\Models\User:42, or the custom scope's name and its value.
 */
export function whoOf(exemption: Pick<Exemption, 'subject' | 'subject_parameter' | 'value'>): { kind: string; value: string } {
    if (exemption.subject === 'ip') {
        return { kind: 'IP', value: exemption.value };
    }

    if (exemption.subject === 'user') {
        // A user is stored as Class:id; a class name holds no colon.
        return { kind: 'User', value: exemption.value.slice(exemption.value.lastIndexOf(':') + 1) };
    }

    const scope = exemption.subject_parameter ?? 'custom';

    return { kind: scope.charAt(0).toUpperCase() + scope.slice(1), value: exemption.value };
}
