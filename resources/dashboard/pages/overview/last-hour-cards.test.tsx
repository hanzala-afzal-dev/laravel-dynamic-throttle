import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { LastHourCards } from '@/pages/overview/last-hour-cards';
import { metricsOf, ruleOf } from '@/test/metrics';
import { mockApi, renderAt } from '@/test/render';

/* SPEC-021 AC-5: the last hour's blocks and would-blocks. */

describe('the last hour\'s cards', () => {
    it('AC-5: sum every rule\'s blocks and would-blocks, with the blocks\' share of the checks', async () => {
        mockApi({
            'GET metrics?period=hour': {
                body: metricsOf('hour', [ruleOf(1, 'Login', [20_000, 0], [1_000, 200], [0, 0]), ruleOf(2, 'Search', [0, 7_000], [0, 34], [50, 6])], 2),
            },
        });
        renderAt(<LastHourCards />);

        expect(await screen.findByText('1,234')).toBeInTheDocument();
        expect(screen.getByText('4.6% of 27,000 checks')).toBeInTheDocument();
        expect(screen.getByText('56')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Blocks in the last hour' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Would-blocks in the last hour' })).toBeInTheDocument();
    });

    it('AC-5: says no rule checked a request when none did', async () => {
        mockApi({ 'GET metrics?period=hour': { body: metricsOf('hour', []) } });
        renderAt(<LastHourCards />);

        expect(await screen.findByText('No rule checked a request.')).toBeInTheDocument();
    });
});
