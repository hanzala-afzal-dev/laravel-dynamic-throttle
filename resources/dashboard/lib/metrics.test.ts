import { describe, expect, it } from 'vitest';
import { series, topRules, totals } from '@/lib/metrics';
import { metricsOf, ruleOf } from '@/test/metrics';

/* SPEC-021 AC-5 to AC-7: the sums and the top rules, from api/metrics. */

const metrics = metricsOf('hour', [
    ruleOf(1, 'Login', [10, 20, 30], [1, 2, 3], [0, 0, 0]),
    ruleOf(2, 'Search', [5, 5, 5], [0, 0, 0], [1, 1, 1]),
    ruleOf(3, null, [100, 0, 0], [7, 0, 0], [0, 0, 0]),
]);

describe('the metrics', () => {
    it('AC-5: totals every rule\'s checks, blocks and would-blocks', () => {
        expect(totals(metrics)).toEqual({ hits: 175, blocks: 13, wouldBlocks: 3 });
    });

    it('AC-6: sums the rules at each step, oldest first', () => {
        expect(series(metrics)).toEqual([
            { time: '2027-01-15T11:58:00+00:00', hits: 115, blocks: 8, wouldBlocks: 1 },
            { time: '2027-01-15T11:59:00+00:00', hits: 25, blocks: 2, wouldBlocks: 1 },
            { time: '2027-01-15T12:00:00+00:00', hits: 35, blocks: 3, wouldBlocks: 1 },
        ]);
    });

    it('AC-7: ranks the rules by blocks, then would-blocks, at most 5, and leaves out those with neither', () => {
        const many = metricsOf('day', [
            ruleOf(1, 'A', [9], [0], [5]),
            ruleOf(2, 'B', [9], [3], [0]),
            ruleOf(3, 'C', [9], [3], [1]),
            ruleOf(4, 'D', [9], [0], [0]),
            ruleOf(5, 'E', [9], [10], [0]),
            ruleOf(6, 'F', [9], [1], [0]),
            ruleOf(7, null, [9], [2], [0]),
        ], 1);

        expect(topRules(many).map((rule) => [rule.id, rule.name, rule.blocks, rule.wouldBlocks])).toEqual([
            [5, 'E', 10, 0],
            [3, 'C', 3, 1],
            [2, 'B', 3, 0],
            [7, null, 2, 0],
            [6, 'F', 1, 0],
        ]);
    });
});
