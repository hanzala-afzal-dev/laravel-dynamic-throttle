import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { HealthCard } from '@/pages/overview/health-card';
import { mockApi, renderAt } from '@/test/render';

/* SPEC-021 AC-3: the health card, from api/health. */

type Check = { check: string; status: string; message: string };

function report(status: string, checks: Check[] = []) {
    return { 'GET health': { body: { status, checks } } };
}

describe('the health card', () => {
    it.each([
        ['ok', 'Healthy'],
        ['warning', 'Warnings'],
        ['error', 'Errors'],
    ])('AC-3: says the report\'s status %s as "%s"', async (status, label) => {
        mockApi(report(status));
        renderAt(<HealthCard />);

        expect(await screen.findByText(label)).toBeInTheDocument();
    });

    it('AC-3: lists no check when all are ok, and links to every check in Settings', async () => {
        mockApi(report('ok', [{ check: 'config', status: 'ok', message: 'The config is valid.' }]));
        renderAt(<HealthCard />);

        await screen.findByText('Healthy');

        expect(screen.queryByRole('list')).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'See every check' })).toHaveAttribute('href', '/settings');
    });

    it('AC-3: lists the first 3 errors and warnings, errors first, and no skipped check', async () => {
        mockApi(
            report('error', [
                { check: 'metrics', status: 'warning', message: 'Telemetry is off.' },
                { check: 'guests', status: 'skipped', message: 'Too little traffic to tell.' },
                { check: 'store', status: 'error', message: 'Connection refused.' },
                { check: 'except', status: 'warning', message: 'The except list skips api/*.' },
                { check: 'order', status: 'warning', message: 'The middleware runs before auth.' },
            ]),
        );
        renderAt(<HealthCard />);

        await screen.findByText('Errors');

        expect(within(screen.getByRole('list')).getAllByRole('listitem').map((item) => item.textContent)).toEqual([
            'Error: Connection refused.',
            'Warning: Telemetry is off.',
            'Warning: The except list skips api/*.',
        ]);
    });

    it('AC-3: asks for the report again on "Check again"', async () => {
        const sent = mockApi(report('ok'));
        renderAt(<HealthCard />);

        await screen.findByText('Healthy');
        await userEvent.click(screen.getByRole('button', { name: 'Check again' }));

        await waitFor(() => expect(sent.filter(([method, path]) => method === 'GET' && path === 'health')).toHaveLength(2));
    });

    it('AC-3: shows why the report failed', async () => {
        mockApi({ 'GET health': { status: 500, body: { message: 'Server Error' } } });
        renderAt(<HealthCard />);

        expect(await screen.findByText('The server failed: Server Error')).toBeInTheDocument();
    });
});
