import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Metrics, Period } from '@/lib/metrics';

/** Live data polls every 5 seconds, and not while the page is hidden (SPEC-020). */
export const LIVE = { refetchInterval: 5_000, refetchIntervalInBackground: false } as const;

/** Data that changes slowly, or costs more to read, refreshes every minute (SPEC-021 decision 4). */
export const EVERY_MINUTE = { refetchInterval: 60_000, refetchIntervalInBackground: false } as const;

export type Me = { can: { view: boolean; manage: boolean }; environment: string };

export type Settings = {
    bypass: { on: boolean; until: string | null };
    scale: { factor: string | null; until: string | null };
};

export type CheckStatus = 'ok' | 'warning' | 'error' | 'skipped';

/** The report dynamic-throttle:health --json prints (SPEC-016). */
export type HealthReport = { status: CheckStatus; checks: { check: string; status: CheckStatus; message: string }[] };

export type RuleCounts = { enforcing: number; dry_run: number; disabled: number };

export function useMe() {
    return useQuery({ queryKey: ['me'], queryFn: () => api<Me>('GET', 'me'), staleTime: 60_000 });
}

/** Whether the user may change things: viewers see no button that does (SPEC-020). */
export function useCanManage(): boolean {
    return useMe().data?.can.manage === true;
}

export function useSettings() {
    return useQuery({ queryKey: ['settings'], queryFn: () => api<Settings>('GET', 'settings'), ...LIVE });
}

export function useHealth() {
    return useQuery({ queryKey: ['health'], queryFn: () => api<HealthReport>('GET', 'health'), ...EVERY_MINUTE });
}

export function useRuleCounts() {
    return useQuery({ queryKey: ['rules', 'counts'], queryFn: () => api<RuleCounts>('GET', 'rules/counts'), ...EVERY_MINUTE });
}

/** The counter store's hour polls with the live data; the day and the week come from the hourly table (SPEC-021 decision 4). */
export function useMetrics(period: Period) {
    return useQuery({ queryKey: ['metrics', period], queryFn: () => api<Metrics>('GET', `metrics?period=${period}`), ...(period === 'hour' ? LIVE : EVERY_MINUTE) });
}

/** The clients a rule blocked most this hour or the last, by identity hash (SPEC-017 AC-7); null where the store keeps none. */
export type Offenders = { hour: string; offenders: { hash: string; count: number }[] | null; clients: number | null };

export function useOffenders(ruleId: number, hour: 'current' | 'previous') {
    return useQuery({ queryKey: ['rules', 'offenders', ruleId, hour], queryFn: () => api<Offenders>('GET', `rules/${ruleId}/offenders?hour=${hour}`), ...EVERY_MINUTE });
}

/** An audit log entry (SPEC-019). */
export type AuditEntry = {
    id: number;
    actor: string;
    action: string;
    target: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    ip: string | null;
    user_agent: string | null;
    created_at: string | null;
};

/** The newest audit entries about one target, such as rule:12, 100 at most (SPEC-019). */
export function useAuditOf(target: string) {
    return useQuery({
        queryKey: ['audit', target],
        queryFn: () => api<{ data: AuditEntry[]; next_cursor: string | null }>('GET', `audit?target=${encodeURIComponent(target)}`),
    });
}
