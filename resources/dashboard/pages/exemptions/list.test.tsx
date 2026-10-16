import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { formatTime } from '@/lib/time';
import { Pages } from '@/pages';
import { ExemptionsList } from '@/pages/exemptions/list';
import { exemptionOf } from '@/test/exemptions';
import { type Answers, mockApi, renderAt, type Sent, viewer } from '@/test/render';
import { pageOf, ruleOf } from '@/test/rules';

/* SPEC-023 AC-2 and AC-3: the list of exemptions and its filters. */

const LOGIN = ruleOf({ id: 3, name: 'Login', pattern: 'login' });
const ORDERS = ruleOf({ id: 4, name: 'Orders', pattern: 'orders/*' });

const OFFICE = exemptionOf({ id: 12, value: '203.0.113.0/24', reason: 'The office', created_at: '2027-01-15T09:30:00+00:00' });
const PARTNER = exemptionOf({ id: 11, rule_id: 3, subject: 'user', value: 'App\\Models\\User:42', reason: 'A partner', expires_at: '2027-01-22T12:00:00+00:00', created_at: '2027-01-15T08:00:00+00:00' });
const TENANT = exemptionOf({ id: 10, rule_id: 4, subject: 'custom', subject_parameter: 'tenant', value: 'acme', expires_at: '2027-01-14T12:00:00+00:00', active: false, created_at: '2027-01-13T12:00:00+00:00' });

function answers(extra: Answers = {}): Answers {
    return { 'GET rules': { body: pageOf([LOGIN, ORDERS]) }, 'GET exemptions?state=active': { body: pageOf([OFFICE, PARTNER]) }, ...extra };
}

const rows = () => within(screen.getByRole('table')).getAllByRole('row').slice(1);
const gets = (sent: Sent) => sent.filter(([method, path]) => method === 'GET' && path.startsWith('exemptions')).map(([, path]) => path);
const local = (time: string) => formatTime(time).local;

describe('the exemptions list', () => {
    it('AC-2: shows who, which rule, the reason, the end and when each was added, newest first', async () => {
        mockApi(viewer(answers({ 'GET exemptions': { body: pageOf([OFFICE, PARTNER, TENANT]) } })));
        renderAt(<ExemptionsList />, '/exemptions?state=all');

        await screen.findByRole('link', { name: 'Orders' });

        expect(rows().map((row) => within(row).getAllByRole('cell').map((cell) => cell.textContent))).toEqual([
            ['IP 203.0.113.0/24', 'Every rule', 'The office', 'Never', local('2027-01-15T09:30:00+00:00')],
            ['User 42', 'Login', 'A partner', local('2027-01-22T12:00:00+00:00'), local('2027-01-15T08:00:00+00:00')],
            ['Tenant acme', 'Orders', '—', `Ended ${local('2027-01-14T12:00:00+00:00')}`, local('2027-01-13T12:00:00+00:00')],
        ]);
        expect(screen.getByRole('link', { name: 'Login' })).toHaveAttribute('href', '/rules/3');
        expect(screen.getByText('42')).toHaveAttribute('title', 'App\\Models\\User:42');
    });

    it('AC-2: names a rule beyond the first 100 by asking for it', async () => {
        const exports = ruleOf({ id: 250, name: 'Exports' });
        const sent = mockApi(viewer(answers({
            'GET exemptions?state=active&rule=250': { body: pageOf([exemptionOf({ rule_id: 250 })]) },
            'GET rules/250': { body: { rule: exports, exemptions: [] } },
        })));
        renderAt(<ExemptionsList />, '/exemptions?rule=250');

        expect(await screen.findByRole('link', { name: 'Exports' })).toHaveAttribute('href', '/rules/250');
        expect(screen.getByLabelText('Rule')).toHaveValue('250');
        expect(within(screen.getByLabelText('Rule')).getByRole('option', { name: 'Exports' })).toBeInTheDocument();
        expect(sent.filter(([, path]) => path === 'rules/250')).toHaveLength(1);
    });

    it('AC-2: says when no exemption is active, or none matches the filters', async () => {
        mockApi(viewer(answers({ 'GET exemptions?state=active': { body: pageOf([]) }, 'GET exemptions?state=active&subject=user': { body: pageOf([]) } })));
        renderAt(<ExemptionsList />, '/exemptions');

        expect(await screen.findByText('No exemption is active.')).toBeInTheDocument();

        await userEvent.selectOptions(screen.getByLabelText('Subject'), 'User');

        expect(await screen.findByText('No exemption matches the filters.')).toBeInTheDocument();
    });

    it('AC-3: starts at the active exemptions', async () => {
        const sent = mockApi(viewer(answers()));
        renderAt(<ExemptionsList />, '/exemptions');

        await screen.findByRole('table');

        expect(gets(sent)).toEqual(['exemptions?state=active']);
        expect(screen.getByLabelText('State')).toHaveValue('active');
    });

    it('AC-3: asks the API with the filters, and puts them in the URL', async () => {
        const sent = mockApi(viewer(answers({ 'GET exemptions?state=expired&subject=user&rule=3': { body: pageOf([]) } })));
        const { history } = renderAt(<ExemptionsList />, '/exemptions');

        await screen.findByRole('option', { name: 'Login' });
        await userEvent.selectOptions(screen.getByLabelText('State'), 'Expired');
        await userEvent.selectOptions(screen.getByLabelText('Subject'), 'User');
        await userEvent.selectOptions(screen.getByLabelText('Rule'), 'Login');

        await waitFor(() => expect(gets(sent)).toContain('exemptions?state=expired&subject=user&rule=3'));
        expect(history.at(-1)).toBe('/exemptions?state=expired&subject=user&rule=3');
    });

    it('AC-3: lists every state, and the exemptions on every rule', async () => {
        const sent = mockApi(viewer(answers({ 'GET exemptions?rule=none': { body: pageOf([OFFICE]) } })));
        const { history } = renderAt(<ExemptionsList />, '/exemptions');

        await screen.findByRole('table');
        await userEvent.selectOptions(screen.getByLabelText('State'), 'All');
        await userEvent.selectOptions(screen.getByLabelText('Rule'), 'Every rule');

        await waitFor(() => expect(gets(sent)).toContain('exemptions?rule=none'));
        expect(history.at(-1)).toBe('/exemptions?state=all&rule=none');
    });

    it('AC-3: applies the filters a URL holds, as a rule\'s page links to them', async () => {
        const sent = mockApi(viewer(answers({ 'GET exemptions?state=expired&rule=3': { body: pageOf([PARTNER]) } })));
        renderAt(<ExemptionsList />, '/exemptions?rule=3&state=expired');

        expect(await screen.findByText('A partner')).toBeInTheDocument();
        expect(gets(sent)).toEqual(['exemptions?state=expired&rule=3']);
        expect(screen.getByLabelText('State')).toHaveValue('expired');
        expect(screen.getByLabelText('Rule')).toHaveValue('3');
    });

    it('AC-3: pages through the exemptions, keeping the filters', async () => {
        const sent = mockApi(viewer(answers({ 'GET exemptions?state=active': { body: pageOf([OFFICE], 1, 2) }, 'GET exemptions?state=active&page=2': { body: pageOf([PARTNER], 2, 2) } })));
        const { history } = renderAt(<ExemptionsList />, '/exemptions');

        expect(await screen.findByText('Page 1 of 2')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Next page' }));

        expect(await screen.findByText('Page 2 of 2')).toBeInTheDocument();
        expect(gets(sent)).toContain('exemptions?state=active&page=2');
        expect(history.at(-1)).toBe('/exemptions?page=2');
    });

    it('AC-2: is the page at /exemptions, loaded on demand', async () => {
        mockApi(viewer(answers()));
        renderAt(<Pages />, '/exemptions');

        expect(await screen.findByRole('combobox', { name: 'State' }, { timeout: 5_000 })).toBeInTheDocument();
    });
});
