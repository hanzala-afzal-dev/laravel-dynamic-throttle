import type { Metrics, Period } from '@/lib/metrics';

/** An answer of api/metrics, ending at 2027-01-15 12:00 UTC (SPEC-017 AC-5). */
export function metricsOf(period: Period, rules: Metrics['rules'], steps = 3): Metrics {
    const step = period === 'hour' ? 60 : 3_600;
    const end = Date.parse('2027-01-15T12:00:00Z') / 1_000;

    return {
        period,
        step_seconds: step,
        times: Array.from({ length: steps }, (_, index) => new Date((end - (steps - 1 - index) * step) * 1_000).toISOString().replace('.000Z', '+00:00')),
        rules,
    };
}

/** A rule's series: its checks, blocks and would-blocks at each step. */
export function ruleOf(id: number, name: string | null, hits: number[], blocks: number[], wouldBlocks: number[]): Metrics['rules'][number] {
    return { id, name, hits, blocks, would_blocks: wouldBlocks };
}
