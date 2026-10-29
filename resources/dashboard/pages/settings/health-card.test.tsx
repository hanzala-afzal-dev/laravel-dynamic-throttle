import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { SettingsHealthCard } from '@/pages/settings/health-card';
import { mockApi, renderAt, viewer } from '@/test/render';

/* SPEC-031 AC-3: every health check, worst first, with what it looks for and what to do. */

const REPORT = {
    status: 'error',
    checks: [
        { check: 'config', status: 'ok', message: 'The config is valid.' },
        { check: 'store', status: 'warning', message: 'The file store counts per server: use Redis.' },
        { check: 'metrics', status: 'skipped', message: 'Telemetry is off.' },
        { check: 'manifest', status: 'error', message: 'No manifest in the cache: run php artisan dynamic-throttle:compile.' },
    ],
};

const rows = () => within(screen.getByRole('table')).getAllByRole('row').slice(1);
const cells = (row: HTMLElement) => within(row).getAllByRole('cell');

describe('the Settings page\'s health card', () => {
    it('AC-3: lists every check, errors first, then warnings, ok and skipped, with its message', async () => {
        mockApi(viewer({ 'GET health': { body: REPORT } }));
        renderAt(<SettingsHealthCard />, '/settings');

        await screen.findByRole('table');

        expect(screen.getByText('Errors')).toBeInTheDocument();
        expect(rows().map((row) => [cells(row)[0]?.textContent, cells(row)[2]?.textContent])).toEqual([
            ['Error', 'No manifest in the cache: run php artisan dynamic-throttle:compile.'],
            ['Warning', 'The file store counts per server: use Redis.'],
            ['OK', 'The config is valid.'],
            ['Skipped', 'Telemetry is off.'],
        ]);
    });

    it('AC-3: names each check, with what it looks for', async () => {
        mockApi(viewer({ 'GET health': { body: REPORT } }));
        renderAt(<SettingsHealthCard />, '/settings');

        await screen.findByRole('table');
        const [manifest, store] = rows();

        expect(within(cells(manifest!)[1]!).getByText('manifest')).toBeInTheDocument();
        expect(cells(manifest!)[1]).toHaveTextContent('No manifest in the cache, so no rule applies.');
        expect(cells(store!)[1]).toHaveTextContent('What the counter store guarantees.');
    });

    it('issue #153: labels what each check looks for, so under OK it never reads as what the check found', async () => {
        mockApi(viewer({ 'GET health': { body: REPORT } }));
        renderAt(<SettingsHealthCard />, '/settings');

        await screen.findByRole('table');
        const config = rows()[2]!;

        expect(cells(config)[0]).toHaveTextContent('OK');
        expect(within(cells(config)[1]!).getByText(/^Looks for:/)).toHaveTextContent('Looks for: A config the counter driver cannot be built from');
    });

    it('SPEC-035 AC-12: says what the unreached check looks for', async () => {
        mockApi(viewer({ 'GET health': { body: { status: 'ok', checks: [{ check: 'unreached', status: 'ok', message: 'Every rule that matches a route of the app matches one that dynamic.throttle limits.' }] } } }));
        renderAt(<SettingsHealthCard />, '/settings');

        await screen.findByRole('table');

        expect(cells(rows()[0]!)[1]).toHaveTextContent('Rules that match only routes dynamic.throttle never limits');
    });

    it('AC-3: asks api/health again on "Check again"', async () => {
        const sent = mockApi(viewer({ 'GET health': { body: REPORT } }));
        renderAt(<SettingsHealthCard />, '/settings');

        await screen.findByRole('table');
        await userEvent.click(screen.getByRole('button', { name: 'Check again' }));

        await waitFor(() => expect(sent.filter(([, path]) => path === 'health')).toHaveLength(2));
    });
});

describe('the Settings page\'s health card, from the review', () => {
    it('AC-9: names its table, announces the overall status, and says which checks need Redis', async () => {
        mockApi(viewer({ 'GET health': { body: { status: 'ok', checks: [{ check: 'dominant-ip', status: 'ok', message: 'Too few requests to tell.' }] } } }));
        renderAt(<SettingsHealthCard />, '/settings');

        const table = await screen.findByRole('table', { name: 'Checks' });

        expect(screen.getByRole('status')).toHaveTextContent('Healthy');
        expect(within(table).getAllByRole('row')[1]).toHaveTextContent('Redis and Valkey only.');
    });
});
