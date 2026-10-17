import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ExemptionsList } from '@/pages/exemptions/list';
import { exemptionOf } from '@/test/exemptions';
import { type Answer, type Answers, manager, mockApi, renderAt, type Sent } from '@/test/render';
import { pageOf, ruleOf } from '@/test/rules';

/* SPEC-023 AC-4 and AC-5: adding an exemption, for a while or with no end. */

const LOGIN = ruleOf({ id: 3, name: 'Login', pattern: 'login' });

function answers(extra: Answers = {}): Answers {
    return { 'GET rules': { body: pageOf([LOGIN]) }, 'GET exemptions?state=active': { body: pageOf([]) }, ...extra };
}

const created = (body: unknown): Answer => ({ status: 201, body: { exemption: { ...exemptionOf(), ...(body as object), id: 20 }, changed: true, manifest: { compiled: true, version: 2 } } });
const posts = (sent: Sent) => sent.filter(([method]) => method === 'POST').map(([, , body]) => body);

/** Opens the dialog from the list, and gives queries within it. */
async function openDialog() {
    await userEvent.click(await screen.findByRole('button', { name: 'Add exemption' }));

    return within(await screen.findByRole('dialog', { name: 'Add an exemption' }));
}

describe('adding an exemption', () => {
    it('AC-4: adds an IP range on every rule for 7 days, from now', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2027-01-15T12:00:00Z'));
        const sent = mockApi(manager(answers({ 'POST exemptions': created })));
        renderAt(<ExemptionsList />, '/exemptions');

        const dialog = await openDialog();

        expect(dialog.getByLabelText('Who')).toHaveValue('ip');
        expect(dialog.getByLabelText('Rule')).toHaveValue('');
        expect(dialog.getByLabelText('Length')).toHaveValue('1d');

        await userEvent.type(dialog.getByLabelText('Value'), '203.0.113.0/24');
        await userEvent.selectOptions(dialog.getByLabelText('Length'), '7 days');
        await userEvent.type(dialog.getByLabelText('Reason'), 'The office');
        await userEvent.click(dialog.getByRole('button', { name: 'Add exemption' }));

        await waitFor(() => expect(posts(sent)).toEqual([{ subject: 'ip', subject_parameter: null, value: '203.0.113.0/24', rule_id: null, expires_at: '2027-01-22T12:00:00.000Z', reason: 'The office' }]));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        await waitFor(() => expect(sent.filter(([, path]) => path === 'exemptions?state=active')).toHaveLength(2));
    });

    it('AC-4: adds a user on one rule with no end', async () => {
        const sent = mockApi(manager(answers({ 'POST exemptions': created })));
        renderAt(<ExemptionsList />, '/exemptions');

        const dialog = await openDialog();
        await userEvent.selectOptions(dialog.getByLabelText('Who'), 'User');
        await userEvent.type(dialog.getByLabelText('Value'), '42');
        await userEvent.selectOptions(dialog.getByLabelText('Rule'), 'Login');
        await userEvent.selectOptions(dialog.getByLabelText('Length'), 'No end');
        await userEvent.click(dialog.getByRole('button', { name: 'Add exemption' }));

        await waitFor(() => expect(posts(sent)).toEqual([{ subject: 'user', subject_parameter: null, value: '42', rule_id: 3, expires_at: null, reason: null }]));
    });

    it('AC-4: adds a custom subject with its scope\'s name', async () => {
        const sent = mockApi(manager(answers({ 'POST exemptions': created })));
        renderAt(<ExemptionsList />, '/exemptions');

        const dialog = await openDialog();
        await userEvent.selectOptions(dialog.getByLabelText('Who'), 'Custom');
        await userEvent.type(dialog.getByLabelText('Scope name'), 'tenant');
        await userEvent.type(dialog.getByLabelText('Value'), 'acme');
        await userEvent.selectOptions(dialog.getByLabelText('Length'), 'No end');
        await userEvent.click(dialog.getByRole('button', { name: 'Add exemption' }));

        await waitFor(() => expect(posts(sent)).toEqual([{ subject: 'custom', subject_parameter: 'tenant', value: 'acme', rule_id: null, expires_at: null, reason: null }]));
    });

    it('AC-4: starts on the rule the list shows', async () => {
        mockApi(manager(answers({ 'GET exemptions?state=active&rule=3': { body: pageOf([]) } })));
        renderAt(<ExemptionsList />, '/exemptions?rule=3');

        await screen.findByRole('option', { name: 'Login' });
        const dialog = await openDialog();

        expect(dialog.getByLabelText('Rule')).toHaveValue('3');
    });

    it('AC-4: shows a 422 under its field, and keeps the dialog open', async () => {
        const message = '203.0.113.0/33 is not an IP address or a CIDR range.';
        mockApi(manager(answers({ 'POST exemptions': { status: 422, body: { message: 'The value is invalid.', errors: { value: [message] } } } })));
        renderAt(<ExemptionsList />, '/exemptions');

        const dialog = await openDialog();
        await userEvent.type(dialog.getByLabelText('Value'), '203.0.113.0/33');
        await userEvent.click(dialog.getByRole('button', { name: 'Add exemption' }));

        await waitFor(() => expect(dialog.getByLabelText('Value')).toHaveAccessibleDescription(message));
        expect(screen.getByRole('dialog', { name: 'Add an exemption' })).toBeInTheDocument();
    });

    it('AC-5: Confirm sends the end time the 409 answered, even a minute later', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2027-01-15T12:00:00Z'));
        const sent = mockApi(manager(answers({
            'POST exemptions': (body) => (typeof body === 'object' && body !== null && 'confirm' in body ? created(body) : { status: 409, body: { message: 'A wide range.' } }),
        })));
        renderAt(<ExemptionsList />, '/exemptions');

        const dialog = await openDialog();
        await userEvent.type(dialog.getByLabelText('Value'), '10.0.0.0/7');
        await userEvent.selectOptions(dialog.getByLabelText('Length'), '7 days');
        await userEvent.click(dialog.getByRole('button', { name: 'Add exemption' }));
        const confirm = await screen.findByRole('alertdialog');
        vi.setSystemTime(new Date('2027-01-15T12:01:00Z'));
        await userEvent.click(within(confirm).getByRole('button', { name: 'Confirm' }));

        await waitFor(() => expect(posts(sent).map((body) => (body as { expires_at: string }).expires_at)).toEqual(['2027-01-22T12:00:00.000Z', '2027-01-22T12:00:00.000Z']));
    });

    it('AC-10: keeps the dialog open, and Cancel off, while the exemption is being added', async () => {
        const sent = mockApi(manager(answers({ 'POST exemptions': created })));
        const mocked = globalThis.fetch;
        let answer: () => void = () => {};
        vi.stubGlobal('fetch', (input: string, init?: RequestInit) => (init?.method === 'POST' ? new Promise((resolve) => { answer = () => resolve(mocked(input, init)); }) : mocked(input, init)));
        renderAt(<ExemptionsList />, '/exemptions');

        const dialog = await openDialog();
        await userEvent.type(dialog.getByLabelText('Value'), '203.0.113.7');
        await userEvent.click(dialog.getByRole('button', { name: 'Add exemption' }));

        expect(dialog.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(dialog.getByRole('button', { name: 'Close' })).toBeDisabled();

        await userEvent.keyboard('{Escape}');

        expect(screen.getByRole('dialog', { name: 'Add an exemption' })).toBeInTheDocument();

        answer();

        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(posts(sent)).toHaveLength(1);
    });

    it('AC-5: shows a wide range\'s 409 in a dialog, and Confirm sends the same exemption with confirm', async () => {
        const message = 'An exemption for 0.0.0.0/0 lets 4,294,967,296 addresses through every rule.';
        const sent = mockApi(manager(answers({
            'POST exemptions': (body) => (typeof body === 'object' && body !== null && 'confirm' in body ? created(body) : { status: 409, body: { message } }),
        })));
        renderAt(<ExemptionsList />, '/exemptions');

        const dialog = await openDialog();
        await userEvent.type(dialog.getByLabelText('Value'), '0.0.0.0/0');
        await userEvent.selectOptions(dialog.getByLabelText('Length'), 'No end');
        await userEvent.click(dialog.getByRole('button', { name: 'Add exemption' }));
        const confirm = await screen.findByRole('alertdialog');

        expect(confirm).toHaveTextContent(message);

        await userEvent.click(within(confirm).getByRole('button', { name: 'Confirm' }));

        const exemption = { subject: 'ip', subject_parameter: null, value: '0.0.0.0/0', rule_id: null, expires_at: null, reason: null };
        await waitFor(() => expect(posts(sent)).toEqual([exemption, { ...exemption, confirm: true }]));
    });
});
