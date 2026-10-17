import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import type { AuditEntry } from '@/lib/queries';
import { Pages } from '@/pages';
import { AuditLogPage } from '@/pages/audit';
import { type Answers, manager, mockApi, renderAt, type Sent, viewer } from '@/test/render';

/* SPEC-025: the whole audit log, filtered, newest first, with each change's before and after. */

function entryOf(overrides: Partial<AuditEntry> = {}): AuditEntry {
    return {
        id: 3,
        actor: 'user:App\\Models\\User:42',
        action: 'rule.update',
        target: 'rule:12',
        before: { max_attempts: 5, window_seconds: 60 },
        after: { max_attempts: 10, window_seconds: 30 },
        ip: '203.0.113.9',
        user_agent: 'Mozilla/5.0 (X11; Linux x86_64)',
        created_at: '2027-01-15T12:00:00+00:00',
        ...overrides,
    };
}

const BYPASS = entryOf({ id: 2, actor: 'cli:deploy', action: 'settings.bypass', target: 'setting:bypass', before: null, after: { on: true, until: '2027-01-15T13:00:00+00:00' }, ip: null, user_agent: null });
const EXEMPTION = entryOf({ id: 1, actor: 'guest', action: 'exemption.delete', target: 'exemption:5', before: { subject: 'ip', value: '203.0.113.0/24' }, after: null });

function pageOf(entries: AuditEntry[], next: string | null = null) {
    return { data: entries, path: '/dynamic-throttle/api/audit', per_page: 100, next_cursor: next, next_page_url: null, prev_cursor: null, prev_page_url: null };
}

const table = () => screen.getByRole('table', { name: 'Audit log entries' });
const rows = () => within(table()).getAllByRole('row').slice(1).filter((row) => within(row).queryAllByRole('cell').length > 1);
const cells = (row: HTMLElement) => within(row).getAllByRole('cell').map((cell) => cell.textContent);
const reads = (sent: Sent) => sent.filter(([method, path]) => method === 'GET' && path.startsWith('audit')).map(([, path]) => path);

function render(answers: Answers, path = '/audit', user = viewer) {
    const sent = mockApi(user(answers));
    const result = renderAt(<AuditLogPage />, path);

    return { sent, ...result };
}

describe('the audit log', () => {
    it('AC-1: lists each entry\'s time, actor, action in words, target and what changed, in the API\'s order', async () => {
        render({ 'GET audit': { body: pageOf([entryOf(), BYPASS, EXEMPTION]) } });

        await screen.findByRole('table', { name: 'Audit log entries' });

        expect(rows().map((row) => cells(row).slice(1, 5))).toEqual([
            ['user:App\\Models\\User:42', 'Rule changed', 'rule:12', 'max_attempts: 5 → 10; window_seconds: 60 → 30'],
            ['cli:deploy', 'Bypass on', 'setting:bypass', 'on: true, until: 2027-01-15T13:00:00+00:00'],
            ['guest', 'Exemption removed', 'exemption:5', 'was subject: ip, value: 203.0.113.0/24'],
        ]);
        expect(within(rows()[0]!).getByRole('time')).toHaveAttribute('dateTime', '2027-01-15T12:00:00+00:00');
    });

    it('AC-1: tells an emergency setting turned off from one turned on, with what it was', async () => {
        render({
            'GET audit': {
                body: pageOf([
                    entryOf({ id: 5, action: 'settings.bypass', target: 'setting:bypass_all', before: { value: '1', expires_at: null }, after: null }),
                    entryOf({ id: 4, action: 'settings.scale', target: 'setting:scale_factor', before: null, after: { value: '0.5', expires_at: null } }),
                    entryOf({ id: 3, action: 'settings.scale', target: 'setting:scale_factor', before: { value: '0.5', expires_at: null }, after: null }),
                ]),
            },
        });

        await screen.findByRole('table', { name: 'Audit log entries' });

        expect(rows().map((row) => cells(row).slice(2, 5))).toEqual([
            ['Bypass off', 'setting:bypass_all', 'was value: 1, expires_at: none'],
            ['Scale factor set', 'setting:scale_factor', 'value: 0.5, expires_at: none'],
            ['Scale factor off', 'setting:scale_factor', 'was value: 0.5, expires_at: none'],
        ]);
    });

    it('AC-1: links a rule\'s target to its page', async () => {
        render({ 'GET audit': { body: pageOf([entryOf(), BYPASS]) } });

        await screen.findByRole('table', { name: 'Audit log entries' });

        expect(within(rows()[0]!).getByRole('link', { name: 'rule:12' })).toHaveAttribute('href', '/rules/12');
        expect(within(rows()[1]!).queryByRole('link')).not.toBeInTheDocument();
    });

    it('AC-2: shows an entry\'s before and after side by side, in full, with its IP and user agent', async () => {
        render({ 'GET audit': { body: pageOf([entryOf(), BYPASS]) } });

        await screen.findByRole('table', { name: 'Audit log entries' });
        await userEvent.click(within(rows()[0]!).getByRole('button', { name: 'Details' }));
        const details = within(screen.getByRole('region', { name: 'Details of entry 3' }));

        expect(details.getByRole('heading', { name: 'Before' }).nextElementSibling?.textContent).toBe(JSON.stringify({ max_attempts: 5, window_seconds: 60 }, null, 2));
        expect(details.getByRole('heading', { name: 'After' }).nextElementSibling?.textContent).toBe(JSON.stringify({ max_attempts: 10, window_seconds: 30 }, null, 2));
        expect(details.getByText('203.0.113.9')).toBeInTheDocument();
        expect(details.getByText('Mozilla/5.0 (X11; Linux x86_64)')).toBeInTheDocument();

        await userEvent.click(within(rows()[0]!).getByRole('button', { name: 'Details' }));

        expect(screen.queryByRole('region', { name: 'Details of entry 3' })).not.toBeInTheDocument();
    });

    it('AC-2: says when an entry has no before, IP or user agent', async () => {
        render({ 'GET audit': { body: pageOf([BYPASS]) } });

        await screen.findByRole('table', { name: 'Audit log entries' });
        await userEvent.click(within(rows()[0]!).getByRole('button', { name: 'Details' }));
        const details = within(screen.getByRole('region', { name: 'Details of entry 2' }));

        expect(details.getByRole('heading', { name: 'Before' }).nextElementSibling?.textContent).toBe('None');
        expect(details.getAllByText('Not recorded')).toHaveLength(2);
    });

    it('AC-3: sends the filters to the API, and keeps them in the page\'s URL', async () => {
        const from = new Date('2027-01-15T10:00').toISOString();
        const until = new Date('2027-01-15T14:30').toISOString();
        const query = `action=rule.update&target=rule%3A12&actor=cli%3Adeploy&from=${encodeURIComponent(from)}&until=${encodeURIComponent(until)}`;
        const { sent, history } = render({ 'GET audit': { body: pageOf([BYPASS]) }, [`GET audit?${query}`]: { body: pageOf([entryOf()]) } });

        await screen.findByRole('table', { name: 'Audit log entries' });
        await userEvent.selectOptions(screen.getByLabelText('Action'), 'Rule changed');
        await userEvent.type(screen.getByLabelText('Target'), 'rule:12');
        await userEvent.type(screen.getByLabelText('Actor'), 'cli:deploy');
        fireEvent.change(screen.getByLabelText('From'), { target: { value: '2027-01-15T10:00' } });
        fireEvent.change(screen.getByLabelText('Until'), { target: { value: '2027-01-15T14:30' } });
        await userEvent.click(screen.getByRole('button', { name: 'Apply filters' }));

        await waitFor(() => expect(cells(rows()[0]!)[2]).toBe('Rule changed'));
        expect(reads(sent)).toEqual(['audit', `audit?${query}`]);
        expect(history.at(-1)).toBe(`/audit?${query}`);
    });

    it('AC-3: opens filtered from a URL with filters, as a rule\'s History links', async () => {
        const { sent } = render({ 'GET audit?target=rule%3A12': { body: pageOf([entryOf()]) } }, '/audit?target=rule%3A12');

        await screen.findByRole('table', { name: 'Audit log entries' });

        expect(screen.getByLabelText('Target')).toHaveValue('rule:12');
        expect(reads(sent)).toEqual(['audit?target=rule%3A12']);
    });

    it('AC-3: shows the whole log again on "Clear filters"', async () => {
        const { sent, history } = render({ 'GET audit?target=rule%3A12': { body: pageOf([entryOf()]) }, 'GET audit': { body: pageOf([entryOf(), BYPASS]) } }, '/audit?target=rule%3A12');

        await screen.findByRole('table', { name: 'Audit log entries' });
        await userEvent.click(screen.getByRole('button', { name: 'Clear filters' }));

        await waitFor(() => expect(rows()).toHaveLength(2));
        expect(screen.getByLabelText('Target')).toHaveValue('');
        expect(reads(sent)).toEqual(['audit?target=rule%3A12', 'audit']);
        expect(history.at(-1)).toBe('/audit');
    });

    it('AC-4: loads the next page below with next_cursor, and offers no more after the last', async () => {
        const { sent } = render({
            'GET audit?target=rule%3A12': { body: pageOf([entryOf()], 'eyJpZCI6M30') },
            'GET audit?target=rule%3A12&cursor=eyJpZCI6M30': { body: pageOf([entryOf({ id: 1, action: 'rule.create', before: null, after: { name: 'Login' } })]) },
        }, '/audit?target=rule%3A12');

        await screen.findByRole('table', { name: 'Audit log entries' });
        await userEvent.click(screen.getByRole('button', { name: 'Load more' }));

        await waitFor(() => expect(rows()).toHaveLength(2));
        expect(cells(rows()[1]!)[2]).toBe('Rule created');
        expect(screen.queryByRole('button', { name: 'Load more' })).not.toBeInTheDocument();
        expect(reads(sent)).toEqual(['audit?target=rule%3A12', 'audit?target=rule%3A12&cursor=eyJpZCI6M30']);
    });

    it.each([
        ['an empty log', '/audit', 'GET audit', { body: pageOf([]) }, 'No change has been recorded yet.'],
        ['an empty filtered log', '/audit?actor=cli%3Ajane', 'GET audit?actor=cli%3Ajane', { body: pageOf([]) }, 'No entry matches the filters.'],
        ['a missing table', '/audit', 'GET audit', { status: 503, body: { message: 'The dynamic_throttle_audit_log table does not exist.' } }, 'The dynamic_throttle_audit_log table does not exist.'],
        ['an until before from', '/audit?from=2027-01-15T10%3A00%3A00.000Z&until=2027-01-14T10%3A00%3A00.000Z', 'GET audit?from=2027-01-15T10%3A00%3A00.000Z&until=2027-01-14T10%3A00%3A00.000Z', { status: 422, body: { message: 'The until field must be a date after or equal to from.', errors: { until: ['The until field must be a date after or equal to from.'] } } }, 'The until field must be a date after or equal to from.'],
    ])('AC-5: says so for %s', async (_, path, request, answer, message) => {
        render({ [request]: answer }, path);

        expect(await screen.findByText(message)).toBeInTheDocument();
    });
});

describe('the Audit log page', () => {
    it('AC-6: is the page at /audit, loaded on demand, with no control that changes anything, for a manager too', async () => {
        mockApi(manager({ 'GET audit': { body: pageOf([entryOf()], 'eyJpZCI6M30') } }));
        renderAt(<Pages />, '/audit');

        expect(await screen.findByRole('heading', { level: 1, name: 'Audit log' }, { timeout: 5_000 })).toBeInTheDocument();
        await screen.findByRole('table', { name: 'Audit log entries' });

        expect(screen.getAllByRole('button').map((button) => button.textContent)).toEqual(['Apply filters', 'Clear filters', 'Details', 'Load more']);
    });
});
