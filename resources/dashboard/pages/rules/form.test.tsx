import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Pages } from '@/pages';
import { RuleForm } from '@/pages/rules/form';
import { type Answer, manager, mockApi, renderAt, type Sent, viewer } from '@/test/render';
import { ruleOf } from '@/test/rules';
import type { Rule } from '@/lib/rules';

/* SPEC-022 AC-5 to AC-7: the form, its templates, edit and duplicate. */

const LOGIN = ruleOf({ id: 3, name: 'Login', method: 'POST', pattern: 'login', max_attempts: 5, window_seconds: 60, priority: 10, response_override: { message: 'Slow down.' } });

const created = (body: unknown): Answer => ({ status: 201, body: { rule: { ...(body as object), id: 7 }, changed: true, manifest: { compiled: true, version: 2 } } });
const sentTo = (sent: Sent, method: string) => sent.filter(([sentMethod]) => sentMethod === method).map(([, path, body]) => [path, body]);

describe('the rule form', () => {
    it('AC-5: fills the form from a template, in dry-run, and creates the rule', async () => {
        const sent = mockApi(manager({ 'POST rules': created }));
        const { history } = renderAt(<RuleForm />, '/rules/new');

        await userEvent.selectOptions(await screen.findByLabelText('Template'), 'Login');

        expect(screen.getByLabelText('Name')).toHaveValue('Login');
        expect(screen.getByLabelText('Pattern')).toHaveValue('login');
        expect(screen.getByLabelText('Mode')).toHaveValue('dry_run');

        await userEvent.click(screen.getByRole('button', { name: 'Create rule' }));

        await waitFor(() => expect(sentTo(sent, 'POST')).toEqual([[
            'rules',
            {
                name: 'Login',
                description: null,
                method: 'POST',
                pattern: 'login',
                scope: 'ip',
                scope_parameter: null,
                max_attempts: 5,
                window_seconds: 60,
                priority: 10,
                mode: 'dry_run',
                on_failure: 'open',
                on_missing_identity: 'ip',
                response_override: { message: 'Too many login attempts. Try again in a minute.' },
                enabled: true,
            },
        ]]));
        await waitFor(() => expect(history.at(-1)).toBe('/rules/7'));
    });

    it('AC-5: sends the window in seconds, from a number and a unit, and the header scope\'s name', async () => {
        const sent = mockApi(manager({ 'POST rules': created }));
        renderAt(<RuleForm />, '/rules/new');

        await userEvent.type(await screen.findByLabelText('Name'), 'Tenants');
        await userEvent.type(screen.getByLabelText('Pattern'), 'api/*');
        await userEvent.selectOptions(screen.getByLabelText('Per'), 'Header');
        await userEvent.type(screen.getByLabelText('Header name'), 'X-Tenant');
        await userEvent.clear(screen.getByLabelText('Attempts'));
        await userEvent.type(screen.getByLabelText('Attempts'), '1000');
        await userEvent.clear(screen.getByLabelText('Window'));
        await userEvent.type(screen.getByLabelText('Window'), '2');
        await userEvent.selectOptions(screen.getByLabelText('Window unit'), 'hours');
        await userEvent.click(screen.getByRole('button', { name: 'Create rule' }));

        await waitFor(() => expect(sentTo(sent, 'POST')[0]?.[1]).toMatchObject({ scope: 'header', scope_parameter: 'X-Tenant', max_attempts: 1000, window_seconds: 7200 }));
    });

    it('AC-6: shows each field\'s message under it', async () => {
        mockApi(manager({ 'POST rules': { status: 422, body: { message: 'The name field is required.', errors: { name: ['The name field is required.'], pattern: ['The pattern must be at most 255 bytes, got 300.'] } } } }));
        renderAt(<RuleForm />, '/rules/new');

        await userEvent.click(await screen.findByRole('button', { name: 'Create rule' }));

        await waitFor(() => expect(screen.getByLabelText('Name')).toHaveAccessibleDescription('The name field is required.'));
        expect(screen.getByLabelText('Pattern')).toHaveAccessibleDescription('The pattern must be at most 255 bytes, got 300.');
        expect(screen.getByLabelText('Name')).toHaveAttribute('aria-invalid', 'true');
    });

    it('AC-6: refuses a 429 body that is not JSON, before sending', async () => {
        const sent = mockApi(manager({ 'POST rules': created }));
        renderAt(<RuleForm />, '/rules/new');

        await userEvent.selectOptions(await screen.findByLabelText('Template'), 'Login');
        await userEvent.clear(screen.getByLabelText('429 body'));
        await userEvent.type(screen.getByLabelText('429 body'), 'not json');
        await userEvent.click(screen.getByRole('button', { name: 'Create rule' }));

        expect(screen.getByLabelText('429 body')).toHaveAccessibleDescription('The 429 body must be a JSON object, such as {"message": "Too many requests."}.');
        expect(sentTo(sent, 'POST')).toEqual([]);
    });

    it('AC-7: edits only the fields that changed, confirming what the API asks', async () => {
        const message = 'Rule 3 (Login) stops limiting requests while it runs in dry-run.';
        const sent = mockApi(manager({
            'GET rules/3': { body: { rule: LOGIN, exemptions: [] } },
            'PATCH rules/3': (body) => (typeof body === 'object' && body !== null && 'confirm' in body ? { body: { rule: { ...LOGIN, max_attempts: 10, mode: 'dry_run' }, changed: true, manifest: { compiled: true, version: 4 } } } : { status: 409, body: { message } }),
        }));
        const { history } = renderAt(<RuleForm id={3} />, '/rules/3/edit');

        await waitFor(() => expect(screen.getByLabelText('Name')).toHaveValue('Login'));
        await userEvent.clear(screen.getByLabelText('Attempts'));
        await userEvent.type(screen.getByLabelText('Attempts'), '10');
        await userEvent.selectOptions(screen.getByLabelText('Mode'), 'dry_run');
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Confirm' }));

        await waitFor(() => expect(sentTo(sent, 'PATCH')).toEqual([
            ['rules/3', { max_attempts: 10, mode: 'dry_run' }],
            ['rules/3', { max_attempts: 10, mode: 'dry_run', confirm: true }],
        ]));
        await waitFor(() => expect(history.at(-1)).toBe('/rules/3'));
    });

    it('AC-7: says so, and sends nothing, when nothing changed', async () => {
        const sent = mockApi(manager({ 'GET rules/3': { body: { rule: LOGIN, exemptions: [] } } }));
        renderAt(<RuleForm id={3} />, '/rules/3/edit');

        await waitFor(() => expect(screen.getByLabelText('Name')).toHaveValue('Login'));
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(await screen.findByText('Nothing changed.')).toBeInTheDocument();
        expect(sentTo(sent, 'PATCH')).toEqual([]);
    });

    it('AC-7: duplicates a rule as "Copy of" it, in dry-run', async () => {
        const sent = mockApi(manager({ 'GET rules/3': { body: { rule: LOGIN, exemptions: [] } }, 'POST rules': created }));
        renderAt(<RuleForm />, '/rules/new?from=3');

        await waitFor(() => expect(screen.getByLabelText('Name')).toHaveValue('Copy of Login'));
        await userEvent.click(screen.getByRole('button', { name: 'Create rule' }));

        await waitFor(() => expect(sentTo(sent, 'POST')[0]?.[1]).toMatchObject({ name: 'Copy of Login', pattern: 'login', mode: 'dry_run', max_attempts: 5, response_override: { message: 'Slow down.' } }));
    });

    it('AC-5: tells a viewer that only managers change rules', async () => {
        mockApi(viewer());
        renderAt(<RuleForm />, '/rules/new');

        expect(await screen.findByText('Only managers can create or change rules.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Create rule' })).not.toBeInTheDocument();
    });
});

describe('the rule form, from the review', () => {
    const patched = (rule: Rule) => (body: unknown): Answer => ({ body: { rule: { ...rule, ...(body as object) }, changed: true, manifest: { compiled: true, version: 4 } } });

    it('AC-12: compares with the rule as the form opened it, so a refetch is never sent back', async () => {
        let rule = LOGIN;
        const sent = mockApi(manager({ 'GET rules/3': () => ({ body: { rule, exemptions: [] } }), 'PATCH rules/3': patched(LOGIN) }));
        const { client } = renderAt(<RuleForm id={3} />, '/rules/3/edit');

        await waitFor(() => expect(screen.getByLabelText('Name')).toHaveValue('Login'));
        // Another admin raises the limit, and the form's query refetches.
        rule = { ...LOGIN, max_attempts: 50 };
        await client.refetchQueries({ queryKey: ['rules', 'one', 3] });
        await userEvent.clear(screen.getByLabelText('Name'));
        await userEvent.type(screen.getByLabelText('Name'), 'Login form');
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(sentTo(sent, 'PATCH')).toEqual([['rules/3', { name: 'Login form' }]]));
    });

    it('AC-13: edits a rule whose 429 body is an empty object, even as an empty list', async () => {
        const empty = { ...LOGIN, response_override: [] as unknown as Rule['response_override'] };
        const sent = mockApi(manager({ 'GET rules/3': { body: { rule: empty, exemptions: [] } }, 'PATCH rules/3': patched(empty) }));
        renderAt(<RuleForm id={3} />, '/rules/3/edit');

        await waitFor(() => expect(screen.getByLabelText('429 body')).toHaveValue('{}'));
        await userEvent.clear(screen.getByLabelText('Name'));
        await userEvent.type(screen.getByLabelText('Name'), 'Login form');
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(sentTo(sent, 'PATCH')).toEqual([['rules/3', { name: 'Login form' }]]));
    });

    it('AC-18: rounds the window to whole seconds, and says it is at most a day', async () => {
        const sent = mockApi(manager({ 'POST rules': created }));
        renderAt(<RuleForm />, '/rules/new');

        await userEvent.selectOptions(await screen.findByLabelText('Template'), 'Login');
        await userEvent.clear(screen.getByLabelText('Window'));
        await userEvent.type(screen.getByLabelText('Window'), '1.1');
        await userEvent.selectOptions(screen.getByLabelText('Window unit'), 'hours');

        expect(screen.getByLabelText('Window')).toHaveAccessibleDescription('At most a day.');

        await userEvent.click(screen.getByRole('button', { name: 'Create rule' }));

        await waitFor(() => expect(sentTo(sent, 'POST')[0]?.[1]).toMatchObject({ window_seconds: 3_960 }));
    });

    it('AC-18: points a field at its error alone once the error replaces the hint', async () => {
        mockApi(manager({ 'POST rules': { status: 422, body: { message: 'The pattern is invalid.', errors: { pattern: ['The pattern must not end with a slash.'] } } } }));
        renderAt(<RuleForm />, '/rules/new');

        await userEvent.selectOptions(await screen.findByLabelText('Template'), 'Login');
        await userEvent.click(screen.getByRole('button', { name: 'Create rule' }));
        await screen.findByText('The pattern must not end with a slash.');

        const described = screen.getByLabelText('Pattern').getAttribute('aria-describedby') ?? '';

        expect(described.split(' ').map((id) => document.getElementById(id)?.textContent)).toEqual(['The pattern must not end with a slash.']);
    });

    it('AC-18: says the page is not found for an edit of an id that is not a number', async () => {
        const sent = mockApi(manager());
        renderAt(<Pages />, '/rules/abc/edit');

        expect(await screen.findByRole('heading', { name: 'Page not found' }, { timeout: 5_000 })).toBeInTheDocument();
        expect(sent.filter(([, path]) => path.startsWith('rules/'))).toEqual([]);
    });
});
