import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Pages } from '@/pages';
import { ConfigCard } from '@/pages/settings/config-card';
import { manager, mockApi, renderAt, viewer } from '@/test/render';

/* SPEC-031 AC-4 and AC-6: the configuration the servers run with, read-only. */

const CONFIG = {
    driver: 'redis, connection default',
    driver_error: null,
    manifest_store: 'redis',
    config: {
        enabled: true,
        driver: 'auto',
        prefix: 'dt:',
        dashboard: { path: 'dynamic-throttle', domain: null, middleware: ['web'], rate_limit: 120 },
        scopes: { ip: { ipv6_prefix: 64 }, user: { guards: null } },
    },
    scopes: ['tenant', 'region'],
    strict: false,
};

// Each row's header (the key) and its cell (the value).
const rowsOf = (table: HTMLElement) => within(table).getAllByRole('row').map((row) => Array.from(row.children, (cell) => cell.textContent));

describe('the configuration card', () => {
    it('AC-4: shows the driver, the manifest store, the custom scopes and strict mode', async () => {
        mockApi(viewer({ 'GET config': { body: CONFIG } }));
        renderAt(<ConfigCard />, '/settings');

        const card = within(await screen.findByRole('region', { name: 'Configuration' }));

        expect(await card.findByRole('table', { name: 'In use' })).toBeInTheDocument();
        expect(rowsOf(card.getByRole('table', { name: 'In use' }))).toEqual([
            ['Counters', 'redis, connection default'],
            ['Manifest cache store', 'redis'],
            ['Custom scopes', 'tenant, region'],
            ['Strict mode', 'Off'],
        ]);
    });

    it('AC-4: shows each group of the config with its keys and values', async () => {
        mockApi(viewer({ 'GET config': { body: CONFIG } }));
        renderAt(<ConfigCard />, '/settings');

        const card = within(await screen.findByRole('region', { name: 'Configuration' }));

        expect(rowsOf(await card.findByRole('table', { name: 'General' }))).toEqual([
            ['enabled', 'true'],
            ['driver', 'auto'],
            ['prefix', 'dt:'],
        ]);
        expect(rowsOf(card.getByRole('table', { name: 'dashboard' }))).toEqual([
            ['path', 'dynamic-throttle'],
            ['domain', 'null'],
            ['middleware', '["web"]'],
            ['rate_limit', '120'],
        ]);
        expect(rowsOf(card.getByRole('table', { name: 'scopes' }))).toEqual([
            ['ip.ipv6_prefix', '64'],
            ['user.guards', 'null'],
        ]);
    });

    it('AC-4: shows a driver error in place of the driver, and none for no custom scope', async () => {
        mockApi(viewer({ 'GET config': { body: { ...CONFIG, driver: null, driver_error: 'Unknown dynamic-throttle.driver "nope": use auto, redis or cache.', scopes: [] } } }));
        renderAt(<ConfigCard />, '/settings');

        const inUse = await screen.findByRole('table', { name: 'In use' });

        expect(rowsOf(inUse).slice(0, 3)).toEqual([
            ['Counters', 'Unknown dynamic-throttle.driver "nope": use auto, redis or cache.'],
            ['Manifest cache store', 'redis'],
            ['Custom scopes', 'None'],
        ]);
    });

    it('AC-4: shows the API\'s error', async () => {
        mockApi(viewer({ 'GET config': { status: 500, body: { message: 'Server Error' } } }));
        renderAt(<ConfigCard />, '/settings');

        expect(await screen.findByText('The server failed: Server Error')).toBeInTheDocument();
    });
});

describe('the Settings page', () => {
    it('AC-6: is the page at /settings, loaded on demand, with no control that changes anything, for a manager too', async () => {
        mockApi(manager({ 'GET config': { body: CONFIG }, 'GET health': { body: { status: 'ok', checks: [] } } }));
        renderAt(<Pages />, '/settings');

        expect(await screen.findByRole('heading', { level: 1, name: 'Settings' }, { timeout: 5_000 })).toBeInTheDocument();
        await screen.findByRole('table', { name: 'In use' });

        expect(screen.queryAllByRole('button').map((button) => button.textContent).filter((name) => name !== 'Check again')).toEqual([]);
        expect(screen.queryAllByRole('textbox')).toEqual([]);
        expect(screen.queryAllByRole('combobox')).toEqual([]);
    });
});

describe('the configuration card, from the review', () => {
    it('AC-9: heads each row with its key, and shows an empty string as ""', async () => {
        mockApi(viewer({ 'GET config': { body: { ...CONFIG, config: { prefix: '', dashboard: { path: 'dynamic-throttle' } } } } }));
        renderAt(<ConfigCard />, '/settings');

        const general = await screen.findByRole('table', { name: 'General' });

        expect(within(general).getAllByRole('rowheader').map((header) => header.textContent)).toEqual(['prefix']);
        expect(rowsOf(general)).toEqual([['prefix', '""']]);
    });
});
