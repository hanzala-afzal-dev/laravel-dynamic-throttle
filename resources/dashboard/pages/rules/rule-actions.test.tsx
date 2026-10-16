import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { RulePage } from '@/pages/rules/rule';
import { metricsOf, ruleOf as seriesOf } from '@/test/metrics';
import { type Answer, manager, mockApi, renderAt, type Sent, viewer } from '@/test/render';
import { ruleOf } from '@/test/rules';

/* SPEC-022 AC-9 and AC-10: promote, delete, and enable or disable, from a rule's page. */

const LOGIN = ruleOf({ id: 3, name: 'Login', method: 'POST', pattern: 'login', max_attempts: 5 });
const TRIAL = ruleOf({ id: 3, name: 'Search', pattern: 'search', mode: 'dry_run' });
const EXEMPTION = { id: 8, rule_id: 3, subject: 'ip', subject_parameter: null, value: '203.0.113.10', reason: null, expires_at: null, active: true, created_at: null, updated_at: null };

function answers(rule = LOGIN, extra: Record<string, Answer | ((body: unknown) => Answer)> = {}) {
    return {
        'GET rules/3': { body: { rule, exemptions: [EXEMPTION] } },
        'GET metrics?period=hour': { body: metricsOf('hour', []) },
        'GET metrics?period=day': { body: metricsOf('day', [seriesOf(3, rule.name, [700, 500], [0, 0], [40, 20]), seriesOf(4, 'Other', [9_000, 9_000], [0, 0], [900, 900])], 2) },
        'GET rules/3/offenders?hour=current': { body: { hour: '2027-01-15T12:00:00+00:00', offenders: [], clients: 0 } },
        'GET audit?target=rule%3A3': { body: { data: [], next_cursor: null, per_page: 100 } },
        ...extra,
    };
}

const changes = (sent: Sent) => sent.filter(([method]) => method !== 'GET').map(([method, path, body]) => [method, path, body]);
const saved = (rule: object): Answer => ({ body: { rule, changed: true, manifest: { compiled: true, version: 5 } } });

describe('a rule\'s actions', () => {
    it('AC-9: shows a dry-run rule\'s last 24 hours, then enforces it', async () => {
        const sent = mockApi(manager(answers(TRIAL, { 'PATCH rules/3': saved({ ...TRIAL, mode: 'enforce' }) })));
        renderAt(<RulePage id={3} />, '/rules/3');

        await userEvent.click(await screen.findByRole('button', { name: 'Promote' }));
        const dialog = await screen.findByRole('alertdialog');

        expect(await within(dialog).findByText('In the last 24 hours it checked 1,200 requests and would have blocked 60 of them (5%).')).toBeInTheDocument();

        await userEvent.click(within(dialog).getByRole('button', { name: 'Enforce' }));

        await waitFor(() => expect(changes(sent)).toEqual([['PATCH', 'rules/3', { mode: 'enforce' }]]));
    });

    it('AC-9: offers no Promote for an enforcing rule', async () => {
        mockApi(manager(answers()));
        renderAt(<RulePage id={3} />, '/rules/3');

        await screen.findByRole('link', { name: 'Edit' });

        expect(screen.queryByRole('button', { name: 'Promote' })).not.toBeInTheDocument();
    });

    it('AC-10: asks before deleting, naming the rule, its exemptions and that it stops limiting, then goes back to the list', async () => {
        const sent = mockApi(manager(answers(LOGIN, { 'DELETE rules/3': saved(LOGIN) })));
        const { history } = renderAt(<RulePage id={3} />, '/rules/3');

        await userEvent.click(await screen.findByRole('button', { name: 'Delete' }));
        const dialog = await screen.findByRole('alertdialog');

        expect(dialog).toHaveTextContent('Delete Login? Its exemptions are deleted with it. It stops limiting requests. This cannot be undone: the audit log keeps its settings.');

        await userEvent.click(within(dialog).getByRole('button', { name: 'Delete' }));

        await waitFor(() => expect(changes(sent)).toEqual([['DELETE', 'rules/3', { confirm: true }]]));
        await waitFor(() => expect(history.at(-1)).toBe('/rules'));
    });

    it('AC-10: does not say a dry-run rule stops limiting', async () => {
        mockApi(manager(answers(TRIAL)));
        renderAt(<RulePage id={3} />, '/rules/3');

        await userEvent.click(await screen.findByRole('button', { name: 'Delete' }));

        expect(await screen.findByRole('alertdialog')).not.toHaveTextContent('It stops limiting requests.');
    });

    it('AC-10: sends nothing when the deletion is cancelled', async () => {
        const sent = mockApi(manager(answers()));
        renderAt(<RulePage id={3} />, '/rules/3');

        await userEvent.click(await screen.findByRole('button', { name: 'Delete' }));
        await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Cancel' }));

        expect(changes(sent)).toEqual([]);
    });

    it('AC-4: disables the rule from its page, confirming what the API asks', async () => {
        const message = 'Rule 3 (Login) stops limiting requests until it is enabled again.';
        const sent = mockApi(manager(answers(LOGIN, {
            'PATCH rules/3': (body) => (typeof body === 'object' && body !== null && 'confirm' in body ? saved({ ...LOGIN, enabled: false }) : { status: 409, body: { message } }),
        })));
        renderAt(<RulePage id={3} />, '/rules/3');

        await userEvent.click(await screen.findByRole('button', { name: 'Disable' }));
        await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Confirm' }));

        await waitFor(() => expect(changes(sent)).toEqual([['PATCH', 'rules/3', { enabled: false }], ['PATCH', 'rules/3', { enabled: false, confirm: true }]]));
    });

    it('AC-10: gives a viewer no Promote, Disable or Delete', async () => {
        mockApi(viewer(answers(TRIAL)));
        const { client } = renderAt(<RulePage id={3} />, '/rules/3');

        await screen.findByRole('heading', { level: 1, name: 'Search' });
        await waitFor(() => expect(client.getQueryState(['me'])?.status).toBe('success'));

        expect(screen.queryAllByRole('button', { name: /Promote|Disable|Delete/ })).toEqual([]);
    });
});
