import { useInfiniteQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AuditEntry } from '@/lib/queries';

/** The audit log's filters, as the page's URL and GET api/audit take them; from and until in ISO 8601 UTC. */
export type AuditFilters = { action: string; target: string; actor: string; from: string; until: string };

export const NO_AUDIT_FILTERS: AuditFilters = { action: '', target: '', actor: '', from: '', until: '' };

/** Each action in words (SPEC-019's nine). */
export const AUDIT_ACTIONS: Record<string, string> = {
    'rule.create': 'Rule created',
    'rule.update': 'Rule changed',
    'rule.enable': 'Rule enabled',
    'rule.disable': 'Rule disabled',
    'rule.delete': 'Rule deleted',
    'exemption.create': 'Exemption added',
    'exemption.delete': 'Exemption removed',
    'settings.bypass': 'Bypass',
    'settings.scale': 'Scale factor',
};

/** A page of the log, as Laravel's cursor paginator gives it. */
export type AuditPage = { data: AuditEntry[]; next_cursor: string | null };

const FIELDS = ['action', 'target', 'actor', 'from', 'until'] as const;

/** The filters a URL's search string holds; an unknown action or a time that is not one is ignored. */
export function auditFiltersOf(search: string): AuditFilters {
    const params = new URLSearchParams(search);
    const value = (name: string) => (params.get(name) ?? '').trim().slice(0, 255);
    const time = (name: string) => (Number.isNaN(Date.parse(value(name))) ? '' : value(name));

    return {
        action: value('action') in AUDIT_ACTIONS ? value('action') : '',
        target: value('target'),
        actor: value('actor'),
        from: time('from'),
        until: time('until'),
    };
}

/** The filters as a query string, in a fixed order: action=rule.update&target=rule%3A12. */
export function auditQuery(filters: AuditFilters): string {
    const params = new URLSearchParams();

    for (const field of FIELDS) {
        if (filters[field] !== '') {
            params.set(field, filters[field]);
        }
    }

    return params.toString();
}

/** The log, newest first, a page of 100 at a time, the next one through its cursor (decision 2). */
export function useAuditLog(filters: AuditFilters) {
    const query = auditQuery(filters);

    return useInfiniteQuery({
        // Under ['audit'], which every change invalidates.
        queryKey: ['audit', 'log', query],
        queryFn: ({ pageParam }) => {
            const params = new URLSearchParams(query);

            if (pageParam !== null) {
                params.set('cursor', pageParam);
            }

            const search = params.toString();

            return api<AuditPage>('GET', search === '' ? 'audit' : `audit?${search}`);
        },
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.next_cursor,
    });
}

/** A value as a person reads it: "none", text as it is, anything else as JSON. */
export function shown(value: unknown): string {
    return value === null || value === undefined ? 'none' : typeof value === 'string' ? value : JSON.stringify(value);
}

/**
 * What an entry changed, in one line: each field as "before → after" for a change, and the
 * fields that were created or deleted otherwise.
 */
export function changeSummary(entry: AuditEntry): string {
    const { before, after } = entry;

    if (before !== null && after !== null) {
        const fields = [...new Set([...Object.keys(after), ...Object.keys(before)])];

        return fields.map((field) => `${field}: ${shown(before[field])} → ${shown(after[field])}`).join('; ');
    }

    const state = after ?? before;

    return state === null ? '' : Object.entries(state).map(([field, value]) => `${field}: ${shown(value)}`).join(', ');
}

/** A UTC time as a datetime-local input shows it, in the browser's zone: 2027-01-15T10:00. */
export function localInputOf(utc: string): string {
    const date = new Date(utc);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const two = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${two(date.getMonth() + 1)}-${two(date.getDate())}T${two(date.getHours())}:${two(date.getMinutes())}`;
}

/** A datetime-local input's value, in the browser's zone, in ISO 8601 UTC (decision 4). */
export function utcOf(local: string): string {
    const date = new Date(local);

    return local === '' || Number.isNaN(date.getTime()) ? '' : date.toISOString();
}
