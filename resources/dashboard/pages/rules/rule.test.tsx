import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { RulePage } from '@/pages/rules/rule';
import { metricsOf, ruleOf as seriesOf } from '@/test/metrics';
import { type Answer, manager, mockApi, renderAt, type Sent, viewer } from '@/test/render';
import { ruleOf } from '@/test/rules';

/* SPEC-022 AC-8 and AC-11: a rule's own page. */

const LOGIN = ruleOf({ id: 3, name: 'Login', description: 'Stops password guessing.', method: 'POST', pattern: 'login', max_attempts: 5, window_seconds: 60, priority: 10, response_override: { message: 'Slow down.' } });
const EXEMPTION = { id: 8, rule_id: 3, subject: 'ip', subject_parameter: null, value: '203.0.113.10', reason: 'The office', expires_at: null, active: true, created_at: '2027-01-15T12:00:00+00:00', updated_at: '2027-01-15T12:00:00+00:00' };
const HASH = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9';

function answers(extra: Record<string, Answer | ((body: unknown) => Answer)> = {}) {
    return {
        'GET rules/3': { body: { rule: LOGIN, exemptions: [EXEMPTION] } },
        'GET metrics?period=hour': { body: metricsOf('hour', [seriesOf(3, 'Login', [20, 40], [2, 10], [0, 0]), seriesOf(4, 'Other', [900, 900], [90, 90], [0, 0])], 2) },
        'GET rules/3/offenders?hour=current': { body: { hour: '2027-01-15T12:00:00+00:00', offenders: [{ hash: HASH, count: 9 }], clients: 3 } },
        'GET rules/3/offenders?hour=previous': { body: { hour: '2027-01-15T11:00:00+00:00', offenders: [], clients: 0 } },
        'GET audit?target=rule%3A3': {
            body: {
                data: [
                    { id: 2, actor: 'user:App\\Models\\User:1', action: 'rule.update', target: 'rule:3', before: { max_attempts: 3 }, after: { max_attempts: 5 }, ip: '198.51.100.7', user_agent: 'Firefox', created_at: '2027-01-15T12:30:00+00:00' },
                    { id: 1, actor: 'cli:deploy', action: 'rule.create', target: 'rule:3', before: null, after: { name: 'Login' }, ip: null, user_agent: null, created_at: '2027-01-15T12:00:00+00:00' },
                ],
                next_cursor: null,
                per_page: 100,
            },
        },
        ...extra,
    };
}

const gets = (sent: Sent) => sent.filter(([method]) => method === 'GET').map(([, path]) => path);

describe('a rule\'s page', () => {
    it('AC-8: shows the rule\'s settings', async () => {
        mockApi(viewer(answers()));
        renderAt(<RulePage id={3} />, '/rules/3');

        expect(await screen.findByRole('heading', { level: 1, name: 'Login' })).toBeInTheDocument();
        const settings = within(screen.getByRole('region', { name: 'Settings' }));

        expect(screen.getByText('Stops password guessing.')).toBeInTheDocument();
        expect(settings.getByText('POST login')).toBeInTheDocument();
        expect(settings.getByText('5 per minute')).toBeInTheDocument();
        expect(settings.getByText('Let requests through')).toBeInTheDocument();
        expect(settings.getByText('{"message":"Slow down."}')).toBeInTheDocument();
    });

    it('AC-8: charts the rule\'s own traffic, not the others\'', async () => {
        mockApi(viewer(answers()));
        renderAt(<RulePage id={3} />, '/rules/3');

        expect(await screen.findByText('60 checks, 12 blocks (20%) and 0 would-blocks in the last hour.')).toBeInTheDocument();
        expect(screen.getByRole('figure', { name: 'Blocks and would-blocks in the last hour' })).toBeInTheDocument();
    });

    it('AC-8: lists the offenders this hour, by identity hash, and the last hour on its tab', async () => {
        const sent = mockApi(viewer(answers()));
        renderAt(<RulePage id={3} />, '/rules/3');

        const offenders = within(await screen.findByRole('region', { name: 'Offenders' }));

        expect(await offenders.findByText('a1b2c3d4e5f6…')).toBeInTheDocument();
        expect(offenders.getByText('9')).toBeInTheDocument();
        expect(offenders.getByText('3 different clients blocked')).toBeInTheDocument();

        await userEvent.click(offenders.getByRole('tab', { name: 'Last hour' }));

        expect(await offenders.findByText('It blocked no client in that hour.')).toBeInTheDocument();
        expect(gets(sent)).toContain('rules/3/offenders?hour=previous');
    });

    it('issue #153: says would-blocks for its offenders while the rule is in dry-run', async () => {
        mockApi(viewer(answers({
            'GET rules/3': { body: { rule: { ...LOGIN, mode: 'dry_run' }, exemptions: [EXEMPTION] } },
            'GET rules/3/offenders?hour=current': { body: { hour: '2027-01-15T12:00:00+00:00', offenders: [{ hash: HASH, count: 9 }], clients: 1 } },
        })));
        renderAt(<RulePage id={3} />, '/rules/3');

        const offenders = within(await screen.findByRole('region', { name: 'Offenders' }));

        expect(await offenders.findByText('1 client would have been blocked')).toBeInTheDocument();
        expect(offenders.getByRole('columnheader', { name: 'Would-blocks' })).toBeInTheDocument();
    });

    it('AC-8: says when the counter store keeps no offenders', async () => {
        mockApi(viewer(answers({ 'GET rules/3/offenders?hour=current': { body: { hour: '2027-01-15T12:00:00+00:00', offenders: null, clients: null } } })));
        renderAt(<RulePage id={3} />, '/rules/3');

        expect(await screen.findByText('The counter store keeps no offenders: only the Redis driver does.')).toBeInTheDocument();
    });

    it('AC-8: lists the rule\'s exemptions, with a link to all of them', async () => {
        mockApi(viewer(answers()));
        renderAt(<RulePage id={3} />, '/rules/3');

        const exemptions = within(await screen.findByRole('region', { name: 'Exemptions' }));

        expect(exemptions.getByText('203.0.113.10')).toBeInTheDocument();
        expect(exemptions.getByText('The office')).toBeInTheDocument();
        expect(exemptions.getByRole('link', { name: 'See its exemptions' })).toHaveAttribute('href', '/exemptions?rule=3');
    });

    it('AC-8: shows the rule\'s history, newest first, with what each change did', async () => {
        mockApi(viewer(answers()));
        renderAt(<RulePage id={3} />, '/rules/3');

        const history = within(await screen.findByRole('region', { name: 'History' }));
        const items = await history.findAllByRole('listitem');

        expect(items.map((item) => item.querySelector('[data-action]')?.textContent)).toEqual(['Changed', 'Created']);
        expect(items[0]).toHaveTextContent('max_attempts: 3 → 5');
        expect(items[0]).toHaveTextContent('user:App\\Models\\User:1');
        expect(items[1]).toHaveTextContent('cli:deploy');
    });

    it('AC-8: gives a manager Edit and Duplicate, and a viewer neither', async () => {
        mockApi(manager(answers()));
        const { unmount } = renderAt(<RulePage id={3} />, '/rules/3');

        expect(await screen.findByRole('link', { name: 'Edit' })).toHaveAttribute('href', '/rules/3/edit');
        expect(screen.getByRole('link', { name: 'Duplicate' })).toHaveAttribute('href', '/rules/new?from=3');
        unmount();

        mockApi(viewer(answers()));
        const { client } = renderAt(<RulePage id={3} />, '/rules/3');

        await screen.findByRole('heading', { level: 1, name: 'Login' });
        await waitFor(() => expect(client.getQueryState(['me'])?.status).toBe('success'));
        expect(screen.queryByRole('link', { name: 'Edit' })).not.toBeInTheDocument();
    });

    it('AC-11: says there is no such rule, with a link to the list', async () => {
        mockApi(viewer({ 'GET rules/9': { status: 404, body: { message: 'Not found.' } } }));
        renderAt(<RulePage id={9} />, '/rules/9');

        expect(await screen.findByText('There is no rule 9.')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Back to the rules' })).toHaveAttribute('href', '/rules');
    });
});
