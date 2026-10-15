import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import type { Period } from '@/lib/metrics';
import { TrafficCard } from '@/pages/overview/traffic-card';
import { metricsOf, ruleOf } from '@/test/metrics';
import { mockApi, renderAt } from '@/test/render';

/* SPEC-021 AC-6 and AC-8: the traffic chart over an hour, a day or a week. */

function Traffic() {
    const [period, setPeriod] = useState<Period>('hour');

    return <TrafficCard period={period} onPeriodChange={setPeriod} />;
}

const hour = metricsOf('hour', [ruleOf(1, 'Login', [10, 20, 30], [1, 2, 3], [0, 0, 0]), ruleOf(2, 'Search', [115, 0, 0], [7, 0, 0], [3, 0, 0])]);
const day = metricsOf('day', [ruleOf(1, 'Login', [2_000], [40], [0])], 1);

describe('the traffic card', () => {
    it('AC-6: shows the last hour first, with its totals and the chart', async () => {
        const sent = mockApi({ 'GET metrics?period=hour': { body: hour } });
        renderAt(<Traffic />);

        expect(await screen.findByText('175 checks, 13 blocks (7.4%) and 3 would-blocks in the last hour.')).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Hour' })).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByRole('figure', { name: 'Blocks and would-blocks in the last hour' })).toBeInTheDocument();
        expect(sent).toContainEqual(['GET', 'metrics?period=hour', undefined]);
    });

    it('AC-6: asks for the day on its tab', async () => {
        const sent = mockApi({ 'GET metrics?period=hour': { body: hour }, 'GET metrics?period=day': { body: day } });
        renderAt(<Traffic />);

        await screen.findByText(/in the last hour\.$/);
        await userEvent.click(screen.getByRole('tab', { name: 'Day' }));

        expect(await screen.findByText('2,000 checks, 40 blocks (2%) and 0 would-blocks in the last day.')).toBeInTheDocument();
        expect(sent).toContainEqual(['GET', 'metrics?period=day', undefined]);
    });

    it('AC-6: says no rule checked a request in the period, with no chart', async () => {
        mockApi({ 'GET metrics?period=hour': { body: metricsOf('hour', []) } });
        renderAt(<Traffic />);

        expect(await screen.findByText('No rule checked a request in the last hour.')).toBeInTheDocument();
        expect(screen.queryByRole('figure')).not.toBeInTheDocument();
    });

    it('AC-8: shows the API\'s message when the counter store is down', async () => {
        mockApi({ 'GET metrics?period=hour': { status: 503, body: { message: 'The counter store is unavailable: Connection refused.' } } });
        renderAt(<Traffic />);

        expect(await screen.findByText('The counter store is unavailable: Connection refused.')).toBeInTheDocument();
    });
});
