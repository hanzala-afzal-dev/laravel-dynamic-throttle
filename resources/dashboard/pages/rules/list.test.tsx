import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Link } from 'wouter';
import { Pages } from '@/pages';
import { RulesList } from '@/pages/rules/list';
import { metricsOf, ruleOf as seriesOf } from '@/test/metrics';
import { type Answer, manager, mockApi, renderAt, type Sent, viewer } from '@/test/render';
import { pageOf, ruleOf } from '@/test/rules';

/* SPEC-022 AC-2 to AC-4: the list of rules, its search and filters, and the switch. */

const LOGIN = ruleOf({ id: 1, name: 'Login', method: 'POST', pattern: 'login', max_attempts: 5, window_seconds: 60, priority: 1 });
const TENANTS = ruleOf({ id: 2, name: 'Tenants', scope: 'header', scope_parameter: 'X-Tenant', max_attempts: 1000, window_seconds: 3600, mode: 'dry_run', priority: 2 });
const OLD = ruleOf({ id: 3, name: 'Old', pattern: 'old/*', max_attempts: 3, window_seconds: 90, enabled: false, priority: 3 });

const lastHour = metricsOf('hour', [seriesOf(1, 'Login', [40, 60], [10, 2], [0, 0]), seriesOf(2, 'Tenants', [500, 0], [0, 0], [7, 0])], 2);

function answers(extra: Record<string, Answer | ((body: unknown) => Answer)> = {}) {
    return { 'GET rules': { body: pageOf([LOGIN, TENANTS, OLD]) }, 'GET metrics?period=hour': { body: lastHour }, ...extra };
}

const rows = () => within(screen.getByRole('table')).getAllByRole('row').slice(1);
const gets = (sent: Sent) => sent.filter(([method, path]) => method === 'GET' && path.startsWith('rules')).map(([, path]) => path);

describe('the rules list', () => {
    it('AC-2: shows each rule in evaluation order, with what it applies to, its scope, limit, mode, status and last hour', async () => {
        mockApi(viewer(answers()));
        renderAt(<RulesList />, '/rules');

        await screen.findByRole('table');
        await screen.findByText('12 blocks');

        expect(rows().map((row) => within(row).getAllByRole('cell').map((cell) => cell.textContent))).toEqual([
            ['Login', 'POST login', 'IP', '5 per minute', 'Enforcing', 'Enabled', '12 blocks'],
            ['Tenants', 'Any api/*', 'Header X-Tenant', '1,000 per hour', 'Dry-run', 'Enabled', '7 would-blocks'],
            ['Old', 'Any old/*', 'IP', '3 per 90 seconds', 'Enforcing', 'Disabled', '—'],
        ]);
        expect(screen.getByRole('link', { name: 'Login' })).toHaveAttribute('href', '/rules/1');
    });

    it('AC-3: asks the API with the search and the filters, and puts them in the URL', async () => {
        const sent = mockApi(viewer(answers({ 'GET rules?search=log': { body: pageOf([LOGIN]) }, 'GET rules?search=log&enabled=true&mode=enforce': { body: pageOf([LOGIN]) } })));
        const { history } = renderAt(<RulesList />, '/rules');

        await screen.findByRole('table');
        await userEvent.type(screen.getByRole('searchbox', { name: 'Search rules' }), 'log');
        await waitFor(() => expect(gets(sent)).toContain('rules?search=log'));
        await userEvent.selectOptions(screen.getByLabelText('Status'), 'Enabled');
        await userEvent.selectOptions(screen.getByLabelText('Mode'), 'Enforcing');

        await waitFor(() => expect(gets(sent)).toContain('rules?search=log&enabled=true&mode=enforce'));
        expect(history.at(-1)).toBe('/rules?search=log&enabled=true&mode=enforce');
        // The search waited for the typing to pause.
        expect(gets(sent).filter((path) => path.startsWith('rules?search=l'))).toEqual(['rules?search=log', 'rules?search=log&enabled=true', 'rules?search=log&enabled=true&mode=enforce']);
    });

    it('AC-3: applies the filters a URL holds', async () => {
        const sent = mockApi(viewer(answers({ 'GET rules?enabled=false&mode=dry_run': { body: pageOf([]) } })));
        renderAt(<RulesList />, '/rules?mode=dry_run&enabled=false');

        expect(await screen.findByText('No rule matches the search and filters.')).toBeInTheDocument();
        expect(gets(sent)).toEqual(['rules?enabled=false&mode=dry_run']);
        expect(screen.getByLabelText('Status')).toHaveValue('false');
        expect(screen.getByLabelText('Mode')).toHaveValue('dry_run');
    });

    it('AC-3: pages through the rules', async () => {
        const sent = mockApi(viewer(answers({ 'GET rules': { body: pageOf([LOGIN], 1, 2) }, 'GET rules?page=2': { body: pageOf([OLD], 2, 2) } })));
        renderAt(<RulesList />, '/rules');

        expect(await screen.findByText('Page 1 of 2')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Next page' }));

        expect(await screen.findByText('Page 2 of 2')).toBeInTheDocument();
        expect(gets(sent)).toContain('rules?page=2');
    });

    it('AC-4: lets a manager disable a rule, confirming what the API asks', async () => {
        const message = 'Rule 1 (Login) stops limiting requests until it is enabled again.';
        const sent = mockApi(manager(answers({
            'PATCH rules/1': (body) => (typeof body === 'object' && body !== null && 'confirm' in body ? { body: { rule: { ...LOGIN, enabled: false }, changed: true, manifest: { compiled: true, version: 3 } } } : { status: 409, body: { message } }),
        })));
        renderAt(<RulesList />, '/rules');

        await userEvent.click(await screen.findByRole('switch', { name: 'Login enabled' }));
        const dialog = await screen.findByRole('alertdialog');

        expect(dialog).toHaveTextContent(message);

        await userEvent.click(within(dialog).getByRole('button', { name: 'Confirm' }));

        await waitFor(() => expect(sent.filter(([method]) => method === 'PATCH').map(([, , body]) => body)).toEqual([{ enabled: false }, { enabled: false, confirm: true }]));
        await waitFor(() => expect(gets(sent).filter((path) => path === 'rules')).toHaveLength(2));
    });

    it('AC-4: gives a manager New rule, and Edit and Duplicate on each rule', async () => {
        mockApi(manager(answers()));
        renderAt(<RulesList />, '/rules');

        expect(await screen.findByRole('link', { name: 'New rule' })).toHaveAttribute('href', '/rules/new');
        expect(screen.getByRole('link', { name: 'Edit Login' })).toHaveAttribute('href', '/rules/1/edit');
        expect(screen.getByRole('link', { name: 'Duplicate Login' })).toHaveAttribute('href', '/rules/new?from=1');
    });

    it('AC-4: shows a viewer no switch, no New rule and no Edit', async () => {
        mockApi(viewer(answers()));
        const { client } = renderAt(<RulesList />, '/rules');

        await screen.findByRole('table');
        await waitFor(() => expect(client.getQueryState(['me'])?.status).toBe('success'));

        expect(screen.queryAllByRole('switch')).toEqual([]);
        expect(screen.queryByRole('link', { name: 'New rule' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Edit Login' })).not.toBeInTheDocument();
    });

    it('AC-2: says there is no rule yet, and offers a manager to create one', async () => {
        mockApi(manager(answers({ 'GET rules': { body: pageOf([]) } })));
        renderAt(<RulesList />, '/rules');

        expect(await screen.findByText('No rule yet.')).toBeInTheDocument();
        expect(await screen.findByRole('link', { name: 'New rule' })).toBeInTheDocument();
    });

    it('AC-2: is the page at /rules, loaded on demand', async () => {
        mockApi(viewer(answers()));
        renderAt(<Pages />, '/rules');

        expect(await screen.findByRole('heading', { level: 1, name: 'Rules' }, { timeout: 5_000 })).toBeInTheDocument();
    });
});

describe('the rules list, from the review', () => {
    const pause = () => new Promise((resolve) => setTimeout(resolve, 400));

    it('AC-16: sends the search without the spaces around it, and none for spaces alone', async () => {
        const sent = mockApi(viewer(answers({ 'GET rules?search=log': { body: pageOf([LOGIN]) } })));
        const { history } = renderAt(<RulesList />, '/rules');
        const search = await screen.findByRole('searchbox', { name: 'Search rules' });

        expect(search).toHaveAttribute('maxLength', '100');

        await userEvent.type(search, '   ');
        await pause();

        expect(gets(sent)).toEqual(['rules']);

        await userEvent.type(search, 'log ');

        await waitFor(() => expect(gets(sent)).toContain('rules?search=log'));
        await pause();
        expect(history.at(-1)).toBe('/rules?search=log');
        expect(search).toHaveValue('   log ');
        expect(gets(sent)).toEqual(['rules', 'rules?search=log']);
    });

    it('AC-17: clears the search box when the page opens without a search, as from the sidebar', async () => {
        mockApi(viewer(answers({ 'GET rules?search=log': { body: pageOf([LOGIN]) } })));
        const { history } = renderAt(
            <>
                <RulesList />
                <Link href="/rules">All rules</Link>
            </>,
            '/rules?search=log',
        );

        await screen.findByRole('table');
        await userEvent.click(screen.getByRole('link', { name: 'All rules' }));
        await pause();

        expect(screen.getByRole('searchbox', { name: 'Search rules' })).toHaveValue('');
        expect(history.at(-1)).toBe('/rules');
    });

    it('AC-17: goes to the last page from a page past it', async () => {
        const sent = mockApi(viewer(answers({ 'GET rules?page=3': { body: { ...pageOf([], 3, 1), total: 3 } } })));
        const { history } = renderAt(<RulesList />, '/rules?page=3');

        await waitFor(() => expect(rows()).toHaveLength(3));
        expect(history.at(-1)).toBe('/rules');
        expect(gets(sent)).toEqual(['rules?page=3', 'rules']);
    });
});
