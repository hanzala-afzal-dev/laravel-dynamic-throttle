import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FeedEntry } from '@/lib/feed';
import { Pages } from '@/pages';
import { FeedCard } from '@/pages/activity/feed-card';
import { type Answers, mockApi, renderAt, viewer } from '@/test/render';

/* SPEC-024 AC-1 to AC-4: the live feed of blocks, newest first, polled with since. */

/** An entry: a POST to login from 203.0.113.7, blocked by rule 3 "Login", at 1_800_000_000_000 + offset ms. */
function entry(offset: number, overrides: Partial<FeedEntry> = {}): FeedEntry {
    return {
        time: 1_800_000_000_000 + offset,
        rule: 3,
        rule_name: 'Login',
        outcome: 'blocked',
        method: 'POST',
        route: 'login',
        ip: '203.0.113.7',
        user: null,
        identity: 'a'.repeat(64),
        ...overrides,
    };
}

/** api/config, with telemetry's keys as given. */
function config(telemetry: Record<string, unknown> = {}): Answers {
    return {
        'GET config': {
            body: {
                driver: 'redis, connection default',
                driver_error: null,
                manifest_store: null,
                config: { telemetry: { enabled: true, sample_rate: 1, live_feed: true, ...telemetry } },
                scopes: [],
                strict: false,
            },
        },
    };
}

const table = () => screen.getByRole('table', { name: 'Blocks in the last hour' });
const rows = () => within(table()).getAllByRole('row').slice(1);
const cells = (row: HTMLElement) => within(row).getAllByRole('cell').map((cell) => cell.textContent);
const feedReads = (sent: [string, string, unknown][]) => sent.filter(([method, path]) => method === 'GET' && path.startsWith('feed')).map(([, path]) => path);

function setVisibility(state: 'visible' | 'hidden') {
    Object.defineProperty(document, 'visibilityState', { value: state, configurable: true });
    window.dispatchEvent(new Event('visibilitychange'));
}

describe('the live feed', () => {
    afterEach(() => {
        setVisibility('visible');
    });

    it('AC-1: lists each entry\'s outcome, rule, method and route, IP and user, newest first', async () => {
        mockApi(viewer({
            ...config(),
            'GET feed': {
                body: {
                    feed: [
                        entry(900, { outcome: 'would_block', rule: 4, rule_name: 'API', method: 'GET', route: 'api/orders/{order}', ip: '2001:db8::1', user: 'App\\Models\\User:42' }),
                        entry(500),
                    ],
                },
            },
        }));
        renderAt(<FeedCard />, '/activity');

        await screen.findByRole('table', { name: 'Blocks in the last hour' });

        expect(rows().map((row) => cells(row).slice(1))).toEqual([
            ['Would block', 'API', 'GET api/orders/{order}', '2001:db8::1', 'App\\Models\\User:42'],
            ['Blocked', 'Login', 'POST login', '203.0.113.7', '—'],
        ]);
        expect(within(rows()[0]!).getByRole('link', { name: 'API' })).toHaveAttribute('href', '/rules/4');
    });

    it('AC-1: shows when, in the browser\'s zone, with the UTC time on hover', async () => {
        mockApi(viewer({ ...config(), 'GET feed': { body: { feed: [entry(500)] } } }));
        renderAt(<FeedCard />, '/activity');

        await screen.findByRole('table', { name: 'Blocks in the last hour' });
        const time = within(rows()[0]!).getByRole('time');

        expect(time).toHaveAttribute('dateTime', new Date(1_800_000_000_500).toISOString());
        expect(time.getAttribute('title')).toMatch(/UTC$/);
    });

    it('AC-1: names a deleted rule by its id, without a link, and a request without a route by its method', async () => {
        mockApi(viewer({ ...config(), 'GET feed': { body: { feed: [entry(500, { rule: 9, rule_name: null, route: null })] } } }));
        renderAt(<FeedCard />, '/activity');

        await screen.findByRole('table', { name: 'Blocks in the last hour' });

        expect(cells(rows()[0]!).slice(2, 4)).toEqual(['Rule #9 (deleted)', 'POST']);
        expect(within(rows()[0]!).queryByRole('link')).not.toBeInTheDocument();
    });

    it('AC-2: asks every 5 seconds for what came since 2 seconds before the newest entry, and adds what is new on top', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const sent = mockApi(viewer({
            ...config(),
            'GET feed': { body: { feed: [entry(500)] } },
            // The API answers the overlap again, which the page already holds.
            'GET feed?since=1799999998500': { body: { feed: [entry(900, { ip: '198.51.100.2' }), entry(500)] } },
            'GET feed?since=1799999998900': { body: { feed: [entry(900, { ip: '198.51.100.2' }), entry(500)] } },
        }));
        renderAt(<FeedCard />, '/activity');

        await screen.findByRole('table', { name: 'Blocks in the last hour' });
        await act(() => vi.advanceTimersByTimeAsync(5_000));
        await waitFor(() => expect(rows()).toHaveLength(2));
        await act(() => vi.advanceTimersByTimeAsync(5_000));

        expect(rows().map((row) => cells(row)[4])).toEqual(['198.51.100.2', '203.0.113.7']);
        expect(feedReads(sent)).toEqual(['feed', 'feed?since=1799999998500', 'feed?since=1799999998900']);
    });

    it('AC-2: keeps the newest 500', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const held = Array.from({ length: 500 }, (_, index) => entry(1_000 - index));
        mockApi(viewer({
            ...config(),
            'GET feed': { body: { feed: held } },
            'GET feed?since=1799999999000': { body: { feed: [entry(1_003), entry(1_002), entry(1_001), ...held.slice(0, 497)] } },
        }));
        renderAt(<FeedCard />, '/activity');

        await screen.findByRole('table', { name: 'Blocks in the last hour' });
        await act(() => vi.advanceTimersByTimeAsync(5_000));
        await waitFor(() => expect(within(rows()[0]!).getByRole('time')).toHaveAttribute('dateTime', new Date(1_800_000_001_003).toISOString()));

        expect(rows()).toHaveLength(500);
        expect(within(rows()[499]!).getByRole('time')).toHaveAttribute('dateTime', new Date(1_800_000_000_504).toISOString());
    });

    it('AC-2: stops polling on Pause, and on Resume asks at once for what came meanwhile', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const sent = mockApi(viewer({
            ...config(),
            'GET feed': { body: { feed: [entry(500)] } },
            'GET feed?since=1799999998500': { body: { feed: [entry(900), entry(500)] } },
        }));
        renderAt(<FeedCard />, '/activity');

        await screen.findByRole('table', { name: 'Blocks in the last hour' });
        await userEvent.click(screen.getByRole('button', { name: 'Pause' }));
        await act(() => vi.advanceTimersByTimeAsync(20_000));

        expect(feedReads(sent)).toEqual(['feed']);

        await userEvent.click(screen.getByRole('button', { name: 'Resume' }));

        await waitFor(() => expect(rows()).toHaveLength(2));
        expect(feedReads(sent)).toEqual(['feed', 'feed?since=1799999998500']);
        expect(screen.getByRole('button', { name: 'Pause' })).toBeInTheDocument();
    });

    it('AC-2: keeps the entries it holds when a poll fails, and says why', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        mockApi(viewer({
            ...config(),
            'GET feed': { body: { feed: [entry(500)] } },
            'GET feed?since=1799999998500': { status: 503, body: { message: 'The Redis telemetry buffer could not read the feed.' } },
        }));
        renderAt(<FeedCard />, '/activity');

        await screen.findByRole('table', { name: 'Blocks in the last hour' });
        await act(() => vi.advanceTimersByTimeAsync(5_000));

        expect(await screen.findByText('The Redis telemetry buffer could not read the feed.')).toBeInTheDocument();
        expect(rows()).toHaveLength(1);
    });

    it('AC-2: adds a block another worker wrote a moment late, in time order', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        mockApi(viewer({
            ...config(),
            'GET feed': { body: { feed: [entry(900), entry(500)] } },
            'GET feed?since=1799999998900': { body: { feed: [entry(900), entry(700, { ip: '198.51.100.7' }), entry(500)] } },
        }));
        renderAt(<FeedCard />, '/activity');

        await screen.findByRole('table', { name: 'Blocks in the last hour' });
        await act(() => vi.advanceTimersByTimeAsync(5_000));
        await waitFor(() => expect(rows()).toHaveLength(3));

        expect(rows().map((row) => cells(row)[4])).toEqual(['203.0.113.7', '198.51.100.7', '203.0.113.7']);
    });

    it('AC-2: adds a second identical block, and never a copy of one it holds', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        mockApi(viewer({
            ...config(),
            'GET feed': { body: { feed: [entry(500), entry(500)] } },
            'GET feed?since=1799999998500': { body: { feed: [entry(500), entry(500), entry(500)] } },
        }));
        renderAt(<FeedCard />, '/activity');

        await screen.findByRole('table', { name: 'Blocks in the last hour' });
        await act(() => vi.advanceTimersByTimeAsync(5_000));
        await waitFor(() => expect(rows()).toHaveLength(3));
        await act(() => vi.advanceTimersByTimeAsync(5_000));

        expect(rows()).toHaveLength(3);
    });

    it('AC-3: narrows the list by outcome and by rule, without asking the API', async () => {
        const sent = mockApi(viewer({
            ...config(),
            'GET feed': {
                body: {
                    feed: [
                        entry(900, { outcome: 'would_block', rule: 4, rule_name: 'API', ip: '198.51.100.4' }),
                        entry(700, { ip: '198.51.100.3' }),
                        entry(500, { rule: 4, rule_name: 'API', ip: '198.51.100.1' }),
                    ],
                },
            },
        }));
        renderAt(<FeedCard />, '/activity');

        await screen.findByRole('table', { name: 'Blocks in the last hour' });
        const reads = feedReads(sent).length;
        await userEvent.selectOptions(screen.getByLabelText('Outcome'), 'Blocked');

        expect(rows().map((row) => cells(row)[4])).toEqual(['198.51.100.3', '198.51.100.1']);

        await userEvent.selectOptions(screen.getByLabelText('Rule'), 'API');

        expect(rows().map((row) => cells(row)[4])).toEqual(['198.51.100.1']);

        await userEvent.selectOptions(screen.getByLabelText('Outcome'), 'All');

        expect(rows().map((row) => cells(row)[4])).toEqual(['198.51.100.4', '198.51.100.1']);
        expect(feedReads(sent)).toHaveLength(reads);
    });

    it('AC-3: says when no entry matches the filters', async () => {
        mockApi(viewer({ ...config(), 'GET feed': { body: { feed: [entry(500)] } } }));
        renderAt(<FeedCard />, '/activity');

        await screen.findByRole('table', { name: 'Blocks in the last hour' });
        await userEvent.selectOptions(screen.getByLabelText('Outcome'), 'Would block');

        expect(screen.getByText('No block matches the filters.')).toBeInTheDocument();
    });

    it.each([
        ['a store that keeps none', { 'GET feed': { body: { feed: null } } }, {}, 'This counter store keeps no live feed. Redis and Valkey do.'],
        ['the feed off', { 'GET feed': { body: { feed: [] } } }, { live_feed: false }, 'The live feed is off: telemetry.live_feed in config/dynamic-throttle.php.'],
        ['telemetry off', { 'GET feed': { body: { feed: [] } } }, { enabled: false }, 'The live feed is off: telemetry.live_feed in config/dynamic-throttle.php.'],
        ['no block', { 'GET feed': { body: { feed: [] } } }, {}, 'Nothing was blocked in the last hour.'],
    ])('AC-4: says so for %s', async (_, feed, telemetry, message) => {
        mockApi(viewer({ ...config(telemetry), ...feed }));
        renderAt(<FeedCard />, '/activity');

        expect(await screen.findByText(message)).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });

    it('AC-4: says the feed is a sample when telemetry samples requests', async () => {
        mockApi(viewer({ ...config({ sample_rate: 0.25 }), 'GET feed': { body: { feed: [entry(500)] } } }));
        renderAt(<FeedCard />, '/activity');

        expect(await screen.findByText('Telemetry samples requests (telemetry.sample_rate 0.25), so this shows about one block in 4.')).toBeInTheDocument();
    });
});

describe('the Activity page', () => {
    it('AC-1: is the page at /activity, loaded on demand, with the live feed', async () => {
        mockApi(viewer({ ...config(), 'GET feed': { body: { feed: [entry(500)] } } }));
        renderAt(<Pages />, '/activity');

        expect(await screen.findByRole('heading', { level: 1, name: 'Activity' }, { timeout: 5_000 })).toBeInTheDocument();
        expect(await screen.findByRole('table', { name: 'Blocks in the last hour' })).toBeInTheDocument();
    });
});
