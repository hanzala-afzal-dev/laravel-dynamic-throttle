import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Pages } from '@/pages';
import { OffendersCard } from '@/pages/activity/offenders-card';
import { type Answers, mockApi, renderAt, type Sent, viewer } from '@/test/render';
import { pageOf, ruleOf } from '@/test/rules';

/* SPEC-024 AC-6: any rule's top offenders, this hour or the last, by identity hash. */

const HASH = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9';
const LOGIN = ruleOf({ id: 3, name: 'Login', pattern: 'login' });
const API = ruleOf({ id: 1, name: 'API' });

function answers(extra: Answers = {}): Answers {
    return {
        'GET rules': { body: pageOf([LOGIN, API]) },
        'GET rules/3/offenders?hour=current': { body: { hour: '2027-01-15T12:00:00+00:00', offenders: [{ hash: HASH, count: 9 }], clients: 3 } },
        ...extra,
    };
}

const reads = (sent: Sent) => sent.filter(([method, path]) => method === 'GET' && path.includes('offenders')).map(([, path]) => path);
const card = async () => within(await screen.findByRole('region', { name: 'Offenders' }));

describe('the offenders card', () => {
    it('AC-6: shows the first rule\'s top clients this hour, by identity hash, with their blocks and how many clients', async () => {
        mockApi(viewer(answers()));
        renderAt(<OffendersCard />, '/activity');

        const offenders = await card();

        expect(await offenders.findByText('a1b2c3d4e5f6…')).toBeInTheDocument();
        expect(offenders.getByText('9')).toBeInTheDocument();
        expect(offenders.getByText('3 different clients blocked')).toBeInTheDocument();
        expect(offenders.getByLabelText('Rule')).toHaveValue('3');
    });

    it('AC-6: asks for the rule chosen by name, and for the last hour on its tab', async () => {
        const sent = mockApi(viewer(answers({
            'GET rules/1/offenders?hour=current': { body: { hour: '2027-01-15T12:00:00+00:00', offenders: [], clients: 0 } },
            'GET rules/1/offenders?hour=previous': { body: { hour: '2027-01-15T11:00:00+00:00', offenders: [{ hash: HASH, count: 4 }], clients: 1 } },
        })));
        renderAt(<OffendersCard />, '/activity');

        const offenders = await card();
        await offenders.findByText('a1b2c3d4e5f6…');
        await userEvent.selectOptions(offenders.getByLabelText('Rule'), 'API');

        expect(await offenders.findByText('It blocked no client in that hour.')).toBeInTheDocument();

        await userEvent.click(offenders.getByRole('tab', { name: 'Last hour' }));

        expect(await offenders.findByText('1 client blocked')).toBeInTheDocument();
        expect(reads(sent)).toEqual(['rules/3/offenders?hour=current', 'rules/1/offenders?hour=current', 'rules/1/offenders?hour=previous']);
    });

    it('issue #153: says would-blocks for a rule in dry-run, which blocks no one', async () => {
        mockApi(viewer(answers({
            'GET rules': { body: pageOf([{ ...LOGIN, mode: 'dry_run' }, API]) },
            'GET rules/3/offenders?hour=previous': { body: { hour: '2027-01-15T11:00:00+00:00', offenders: [], clients: 0 } },
        })));
        renderAt(<OffendersCard />, '/activity');

        const offenders = await card();

        expect(await offenders.findByText('3 different clients would have been blocked')).toBeInTheDocument();
        expect(offenders.getByRole('columnheader', { name: 'Would-blocks' })).toBeInTheDocument();
        expect(offenders.queryByRole('columnheader', { name: 'Blocks' })).not.toBeInTheDocument();

        await userEvent.click(offenders.getByRole('tab', { name: 'Last hour' }));

        expect(await offenders.findByText('It would have blocked no client in that hour.')).toBeInTheDocument();
    });

    it('AC-6: says the counter store keeps none', async () => {
        mockApi(viewer(answers({ 'GET rules/3/offenders?hour=current': { body: { hour: '2027-01-15T12:00:00+00:00', offenders: null, clients: null } } })));
        renderAt(<OffendersCard />, '/activity');

        expect(await (await card()).findByText('The counter store keeps no offenders: only the Redis driver does.')).toBeInTheDocument();
    });

    it('AC-6: says there is no rule yet', async () => {
        const sent = mockApi(viewer({ 'GET rules': { body: pageOf([]) } }));
        renderAt(<OffendersCard />, '/activity');

        expect(await (await card()).findByText('There is no rule yet.')).toBeInTheDocument();
        expect(reads(sent)).toEqual([]);
    });

    it('AC-6: is on the Activity page, below the live feed', async () => {
        mockApi(viewer({
            ...answers(),
            'GET feed': { body: { feed: [] } },
            'GET config': { body: { driver: 'redis', driver_error: null, manifest_store: null, config: { telemetry: { enabled: true, sample_rate: 1, live_feed: true } }, scopes: [], strict: false } },
        }));
        renderAt(<Pages />, '/activity');

        await screen.findByRole('heading', { level: 1, name: 'Activity' }, { timeout: 5_000 });

        await waitFor(() => expect(screen.getAllByRole('region').map((region) => region.getAttribute('aria-labelledby') && document.getElementById(region.getAttribute('aria-labelledby')!)?.textContent)).toEqual(['Live feed', 'Offenders']));
    });
});
