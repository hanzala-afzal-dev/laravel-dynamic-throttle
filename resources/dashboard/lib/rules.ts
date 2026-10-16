import { keepPreviousData, queryOptions, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Exemption } from '@/lib/exemptions';
import { formatCount } from '@/lib/format';

/** A rule, as the API gives it (SPEC-017). */
export type Rule = {
    id: number;
    name: string;
    description: string | null;
    method: string;
    pattern: string;
    scope: 'ip' | 'user' | 'api_token' | 'header' | 'global' | 'custom';
    scope_parameter: string | null;
    max_attempts: number;
    window_seconds: number;
    priority: number;
    mode: 'enforce' | 'dry_run';
    on_failure: 'open' | 'closed';
    on_missing_identity: 'ip' | 'skip';
    response_override: Record<string, unknown> | null;
    enabled: boolean;
    created_at: string | null;
    updated_at: string | null;
};

/** A page of rules, as Laravel's paginator gives it. */
export type RulePage = { data: Rule[]; current_page: number; last_page: number; per_page: number; total: number };

/** The list's search and filters, as GET api/rules and the page's URL take them (SPEC-022). */
export type RuleFilters = { search: string; enabled: '' | 'true' | 'false'; mode: '' | 'enforce' | 'dry_run'; page: number };

/** The filters as a search string, in one order, leaving out the empty ones: search=log&enabled=true. */
export function filterQuery(filters: RuleFilters): string {
    const params = new URLSearchParams();

    if (filters.search !== '') {
        params.set('search', filters.search);
    }

    if (filters.enabled !== '') {
        params.set('enabled', filters.enabled);
    }

    if (filters.mode !== '') {
        params.set('mode', filters.mode);
    }

    if (filters.page > 1) {
        params.set('page', String(filters.page));
    }

    return params.toString();
}

export function useRules(filters: RuleFilters) {
    const query = filterQuery(filters);

    return useQuery({
        queryKey: ['rules', 'list', query],
        queryFn: () => api<RulePage>('GET', query === '' ? 'rules' : `rules?${query}`),
        // The rows stay while the next page or search loads.
        placeholderData: keepPreviousData,
    });
}

/** "Any" for every method. */
export function methodLabel(method: string): string {
    return method === '*' ? 'Any' : method;
}

const SCOPES: Record<Rule['scope'], string> = { ip: 'IP', user: 'User', api_token: 'API token', header: 'Header', global: 'Everyone', custom: 'Custom' };

/** Who a rule counts separately: "IP", or "Header X-Tenant". */
export function scopeLabel(rule: Pick<Rule, 'scope' | 'scope_parameter'>): string {
    return rule.scope_parameter === null ? SCOPES[rule.scope] : `${SCOPES[rule.scope]} ${rule.scope_parameter}`;
}

const UNITS: [number, string][] = [
    [86_400, 'day'],
    [3_600, 'hour'],
    [60, 'minute'],
    [1, 'second'],
];

/** A window as a person says it: "minute", "5 minutes", "90 seconds". */
export function windowLabel(seconds: number): string {
    const [size, unit] = UNITS.find(([size]) => seconds % size === 0) ?? [1, 'second'];
    const count = seconds / size;

    return count === 1 ? unit : `${formatCount(count)} ${unit}s`;
}

/** A rule's limit: "5 per minute", "3 per 90 seconds". */
export function limitLabel(rule: Pick<Rule, 'max_attempts' | 'window_seconds'>): string {
    return `${formatCount(rule.max_attempts)} per ${windowLabel(rule.window_seconds)}`;
}

export function modeLabel(mode: Rule['mode']): string {
    return mode === 'enforce' ? 'Enforcing' : 'Dry-run';
}

/** One rule, with the newest 100 exemptions that name it. */
export function useRule(id: number) {
    return useQuery(ruleQuery(id));
}

/**
 * The name of a rule beyond the first 100, for a page that names rules by id (SPEC-023
 * decision 6). Names change rarely, so each row and filter that asks shares one request.
 */
export function useRuleName(id: number, enabled: boolean) {
    return useQuery({ ...ruleQuery(id), enabled, staleTime: 60_000, select: (found) => found.rule.name });
}

function ruleQuery(id: number) {
    return queryOptions({ queryKey: ['rules', 'one', id], queryFn: () => api<{ rule: Rule; exemptions: Exemption[] }>('GET', `rules/${id}`) });
}
