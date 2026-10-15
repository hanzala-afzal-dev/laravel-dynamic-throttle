/**
 * The Overview's numbers, from api/metrics (SPEC-017 AC-5, SPEC-021): every rule's
 * checks, blocks and would-blocks, summed. A request two rules check counts in
 * both, so the sums are checks, not requests (decision 2).
 */

export type Period = 'hour' | 'day' | 'week';

export type Metrics = {
    period: Period;
    step_seconds: number;
    /** Each step's start, a UTC time, oldest first. */
    times: string[];
    /** The rules with numbers in the period; a rule deleted since has its name null. */
    rules: { id: number; name: string | null; hits: number[]; blocks: number[]; would_blocks: number[] }[];
};

export type Totals = { hits: number; blocks: number; wouldBlocks: number };

export type Point = Totals & { time: string };

export type TopRule = Totals & { id: number; name: string | null };

/** The top rules the Overview lists (SPEC-021). */
const TOP = 5;

const sum = (values: number[]): number => values.reduce((total, value) => total + value, 0);

export function totals(metrics: Metrics): Totals {
    return {
        hits: sum(metrics.rules.map((rule) => sum(rule.hits))),
        blocks: sum(metrics.rules.map((rule) => sum(rule.blocks))),
        wouldBlocks: sum(metrics.rules.map((rule) => sum(rule.would_blocks))),
    };
}

/** Every rule's numbers summed at each step, oldest first. */
export function series(metrics: Metrics): Point[] {
    return metrics.times.map((time, index) => ({
        time,
        hits: sum(metrics.rules.map((rule) => rule.hits[index] ?? 0)),
        blocks: sum(metrics.rules.map((rule) => rule.blocks[index] ?? 0)),
        wouldBlocks: sum(metrics.rules.map((rule) => rule.would_blocks[index] ?? 0)),
    }));
}

/** The rules with the most blocks, then the most would-blocks, leaving out those with neither. */
export function topRules(metrics: Metrics, count = TOP): TopRule[] {
    return metrics.rules
        .map((rule) => ({ id: rule.id, name: rule.name, hits: sum(rule.hits), blocks: sum(rule.blocks), wouldBlocks: sum(rule.would_blocks) }))
        .filter((rule) => rule.blocks > 0 || rule.wouldBlocks > 0)
        .sort((a, b) => b.blocks - a.blocks || b.wouldBlocks - a.wouldBlocks || a.id - b.id)
        .slice(0, count);
}

export function isPeriod(value: string): value is Period {
    return value === 'hour' || value === 'day' || value === 'week';
}
