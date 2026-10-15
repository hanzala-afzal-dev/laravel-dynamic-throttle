import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { TopRulesCard } from '@/pages/overview/top-rules-card';
import { metricsOf, ruleOf } from '@/test/metrics';
import { mockApi, renderAt } from '@/test/render';

/* SPEC-021 AC-7 and AC-8: the rules that blocked most in the chart's period. */

describe('the top rules', () => {
    it('AC-7: lists the rules by blocks, then would-blocks, each linking to its page, a deleted one without a link', async () => {
        mockApi({
            'GET metrics?period=day': {
                body: metricsOf('day', [ruleOf(1, 'Login', [1_500], [6], [0]), ruleOf(2, 'Search', [15], [0], [3]), ruleOf(3, null, [100], [7], [0]), ruleOf(4, 'Quiet', [9], [0], [0])], 1),
            },
        });
        renderAt(<TopRulesCard period="day" />);

        const table = await screen.findByRole('table');
        const rows = within(table).getAllByRole('row').slice(1).map((row) => within(row).getAllByRole('cell').map((cell) => cell.textContent));

        expect(rows).toEqual([
            ['Deleted rule #3', '100', '7', '0'],
            ['Login', '1,500', '6', '0'],
            ['Search', '15', '0', '3'],
        ]);
        expect(screen.getByRole('link', { name: 'Login' })).toHaveAttribute('href', '/rules/1');
        expect(screen.queryByRole('link', { name: 'Deleted rule #3' })).not.toBeInTheDocument();
    });

    it('AC-7: says no rule blocked a request in the period', async () => {
        mockApi({ 'GET metrics?period=hour': { body: metricsOf('hour', [ruleOf(1, 'Login', [5, 5, 5], [0, 0, 0], [0, 0, 0])]) } });
        renderAt(<TopRulesCard period="hour" />);

        expect(await screen.findByText('No rule blocked a request in the last hour.')).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });

    it('AC-8: shows the API\'s message when the hourly table is missing', async () => {
        mockApi({ 'GET metrics?period=week': { status: 503, body: { message: 'The dynamic_throttle_metrics_hourly table does not exist.' } } });
        renderAt(<TopRulesCard period="week" />);

        expect(await screen.findByText('The dynamic_throttle_metrics_hourly table does not exist.')).toBeInTheDocument();
    });
});
