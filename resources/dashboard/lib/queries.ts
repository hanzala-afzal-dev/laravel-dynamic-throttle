import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

/** Live data polls every 5 seconds, and not while the page is hidden (SPEC-020). */
export const LIVE = { refetchInterval: 5_000, refetchIntervalInBackground: false } as const;

export type Me = { can: { view: boolean; manage: boolean }; environment: string };

export type Settings = {
    bypass: { on: boolean; until: string | null };
    scale: { factor: string | null; until: string | null };
};

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
