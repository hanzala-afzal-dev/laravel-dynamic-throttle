import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import type { Explanation, ExplainedRule } from '@/lib/explain';
import { Pages } from '@/pages';
import { ExplainPage } from '@/pages/explain';
import { type Answer, manager, mockApi, renderAt, type Sent, viewer } from '@/test/render';

/* SPEC-026: why a request is limited, from the dashboard, without counting it. */

function ruleOf(overrides: Partial<ExplainedRule> = {}): ExplainedRule {
    return {
        id: '1',
        name: 'Orders API',
        method: '*',
        pattern: 'api/orders',
        priority: 10,
        mode: 'enforce',
        limit: 5,
        max_attempts: 10,
        window_seconds: 60,
        on_failure: 'open',
        scope: 'ip',
        scope_parameter: null,
        status: 'counts',
        identity: 'ip:203.0.113.22',
        hash: '7fd128e0194da655c52ea2019cf1df80f39b174d38548529242b5ded52c7054c',
        exemption: null,
        error: null,
        counter: { count: 3, remaining: 2, next: 'allowed', retry_after: null },
        ...overrides,
    };
}

function explanationOf(overrides: Partial<Explanation> = {}): Explanation {
    return {
        request: { method: 'GET', path: 'api/orders', ip: '203.0.113.22', user: null, guard: null, headers: [] },
        manifest: { version: 1792246406063, rules: 2, exemptions: 1 },
        settings: { bypass: null, scale: { factor: '0.5', until: '2027-01-15T13:00:00Z' } },
        route: { uri: 'api/orders', name: 'orders.index', middleware: ['auth:sanctum', 'dynamic.throttle'], throttled: true, after_auth: true },
        rules: [ruleOf()],
        counters: { read: true, error: null },
        decision: { outcome: 'allowed', reason: null, rule: null, headers: { 'X-RateLimit-Limit': 5, 'X-RateLimit-Remaining': 1 } },
        ...overrides,
    };
}

const posts = (sent: Sent) => sent.filter(([method, path]) => method === 'POST' && path === 'explain').map(([, , body]) => body);
const region = (name: string) => screen.getByRole('region', { name });

async function explain(answer: Answer | ((body: unknown) => Answer), user = viewer) {
    const sent = mockApi(user({ 'POST explain': answer }));
    const result = renderAt(<ExplainPage />, '/explain');
    await userEvent.type(screen.getByLabelText('Path'), '/api/orders');
    await userEvent.click(screen.getByRole('button', { name: 'Explain' }));

    return { sent, ...result };
}

describe('the Explain page\'s form', () => {
    it('AC-1: sends the method, path, IP, user, guard and headers in the body, and keeps them out of the URL', async () => {
        const sent = mockApi(viewer({ 'POST explain': { body: explanationOf() } }));
        const { history } = renderAt(<ExplainPage />, '/explain');

        await userEvent.selectOptions(screen.getByLabelText('Method'), 'POST');
        await userEvent.type(screen.getByLabelText('Path'), '/api/orders');
        await userEvent.type(screen.getByLabelText('IP'), '203.0.113.22');
        await userEvent.type(screen.getByLabelText('User id'), '42');
        await userEvent.type(screen.getByLabelText('Guard'), 'api');
        await userEvent.type(screen.getByLabelText('Headers'), 'Authorization: Bearer abc{Enter}X-Client: mobile');
        await userEvent.click(screen.getByRole('button', { name: 'Explain' }));

        await waitFor(() => expect(posts(sent)).toEqual([{ method: 'POST', path: '/api/orders', ip: '203.0.113.22', user: '42', guard: 'api', headers: ['Authorization: Bearer abc', 'X-Client: mobile'] }]));
        expect(history).toEqual(['/explain']);
    });

    it('AC-1: sends empty optional fields as null, and GET by default', async () => {
        const { sent } = await explain({ body: explanationOf() });

        await waitFor(() => expect(posts(sent)).toEqual([{ method: 'GET', path: '/api/orders', ip: null, user: null, guard: null, headers: null }]));
    });
});

describe('the decision', () => {
    it('AC-2: says the request is allowed, with its rate-limit headers', async () => {
        await explain({ body: explanationOf() });

        const decision = await screen.findByRole('region', { name: 'Decision' });

        expect(decision).toHaveTextContent('Allowed: the next such request goes through.');
        expect(within(decision).getAllByRole('row').map((row) => row.textContent)).toEqual(['X-RateLimit-Limit5', 'X-RateLimit-Remaining1']);
    });

    it('AC-2: names and links the rule that blocks it', async () => {
        await explain({ body: explanationOf({ decision: { outcome: 'rejected', reason: null, rule: '1', headers: { 'X-RateLimit-Limit': 5, 'X-RateLimit-Remaining': 0, 'Retry-After': 30 } } }) });

        const decision = await screen.findByRole('region', { name: 'Decision' });

        expect(decision).toHaveTextContent('Blocked with a 429 by Orders API.');
        expect(within(decision).getByRole('link', { name: 'Orders API' })).toHaveAttribute('href', '/rules/1');
    });

    it.each([
        ['unavailable', null, 'A 503: Orders API could not be checked, and it fails closed.'],
        ['failed', null, 'Allowed anyway: Orders API could not be checked, and it fails open.'],
        ['unknown', null, 'Unknown: the counters could not be read.'],
        ['untouched', 'not_attached', 'Untouched: no route runs dynamic.throttle for this request.'],
        ['untouched', 'disabled', 'Untouched: the package is off (dynamic-throttle.enabled).'],
        ['untouched', 'own_route', 'Untouched: it is one of the dashboard\'s own routes.'],
        ['untouched', 'except', 'Untouched: except lists this path.'],
        ['untouched', 'bypass', 'Untouched: bypass is on.'],
        ['untouched', 'no_rule', 'Untouched: no rule matches this request.'],
    ])('AC-2: says %s, %s, in words', async (outcome, reason, words) => {
        await explain({ body: explanationOf({ decision: { outcome: outcome as Explanation['decision']['outcome'], reason, rule: outcome === 'unavailable' || outcome === 'failed' ? '1' : null, headers: {} } }) });

        expect(await screen.findByRole('region', { name: 'Decision' })).toHaveTextContent(words);
    });
});

describe('the matching rules', () => {
    const table = () => within(region('Matching rules')).getByRole('table');
    const cells = (row: HTMLElement) => within(row).getAllByRole('cell').map((cell) => cell.textContent);

    it('AC-3: lists each rule in order, with its limit, scope, status, identity and counter', async () => {
        await explain({
            body: explanationOf({
                rules: [
                    ruleOf(),
                    ruleOf({ id: '2', name: 'Login', method: 'POST', pattern: 'login', mode: 'dry_run', limit: 3, max_attempts: 3, window_seconds: 300, scope: 'user', status: 'exempt', identity: null, hash: null, exemption: 'user App\\Models\\User:42', counter: null }),
                    ruleOf({ id: '3', name: 'Tenants', scope: 'custom', scope_parameter: 'tenant', status: 'failed', identity: null, hash: null, error: 'The tenant resolver threw.', counter: null }),
                ],
            }),
        });

        await screen.findByRole('region', { name: 'Matching rules' });
        const rows = within(table()).getAllByRole('row').slice(1);

        expect(rows.map(cells)).toEqual([
            ['Orders API', '* api/orders', 'Enforce', '5 per 60 s, scaled from 10', 'ip', 'Counts it', 'ip:203.0.113.22', '3 counted, 2 left, next allowed'],
            ['Login', 'POST login', 'Dry run', '3 per 300 s', 'user', 'Exempt: user App\\Models\\User:42', '—', '—'],
            ['Tenants', '* api/orders', 'Enforce', '5 per 60 s, scaled from 10', 'custom:tenant', 'Failed: The tenant resolver threw.', '—', '—'],
        ]);
        expect(within(rows[0]!).getByRole('link', { name: 'Orders API' })).toHaveAttribute('href', '/rules/1');
    });

    it.each([
        ['skipped', 'Skipped: the request has no identity for it'],
        ['ignored', 'Ignored: over the cap of rules per request'],
        ['not_reached', 'Not reached: an earlier rule failed closed'],
    ])('AC-3: says %s in words', async (status, words) => {
        await explain({ body: explanationOf({ rules: [ruleOf({ status: status as ExplainedRule['status'], identity: null, counter: null })] }) });

        await screen.findByRole('region', { name: 'Matching rules' });

        expect(cells(within(table()).getAllByRole('row')[1]!)[5]).toBe(words);
    });

    it('AC-3: shows a limited counter\'s wait', async () => {
        await explain({ body: explanationOf({ rules: [ruleOf({ counter: { count: 5, remaining: 0, next: 'limited', retry_after: 42 } })] }) });

        await screen.findByRole('region', { name: 'Matching rules' });

        expect(cells(within(table()).getAllByRole('row')[1]!)[7]).toBe('5 counted, 0 left, next limited for 42 s');
    });

    it('AC-3: says no rule matches', async () => {
        await explain({ body: explanationOf({ rules: [], decision: { outcome: 'untouched', reason: 'no_rule', rule: null, headers: {} } }) });

        expect(await within(await screen.findByRole('region', { name: 'Matching rules' })).findByText('No rule matches this request.')).toBeInTheDocument();
    });
});

describe('the context', () => {
    it('AC-4: shows the route, its middleware in order, the manifest, the scale factor and the counters', async () => {
        await explain({ body: explanationOf() });

        const route = await screen.findByRole('region', { name: 'Route' });

        expect(route).toHaveTextContent('api/orders');
        expect(route).toHaveTextContent('orders.index');
        expect(within(route).getAllByRole('listitem').map((item) => item.textContent)).toEqual(['auth:sanctum', 'dynamic.throttle']);
        expect(route).toHaveTextContent('dynamic.throttle runs after authentication.');

        const context = region('Context');

        expect(context).toHaveTextContent('Manifest version 1792246406063: 2 rules, 1 exemption.');
        expect(context).toHaveTextContent('Scale factor 0.5');
        expect(context).toHaveTextContent('Bypass is off.');
        expect(context).toHaveTextContent('The counters were read.');
    });

    it.each([
        [{ throttled: true, after_auth: false }, 'dynamic.throttle runs before authentication, so user rules count a guest.'],
        [{ throttled: false, after_auth: false }, 'This route does not run dynamic.throttle.'],
    ])('AC-4: says where dynamic.throttle runs: %o', async (route, words) => {
        await explain({ body: explanationOf({ route: { ...explanationOf().route!, ...route } }) });

        expect(await screen.findByRole('region', { name: 'Route' })).toHaveTextContent(words);
    });

    it('AC-4: says no route matches, bypass is on, and the counters could not be read', async () => {
        await explain({
            body: explanationOf({
                route: null,
                settings: { bypass: { until: '2027-01-15T13:00:00Z' }, scale: null },
                counters: { read: false, error: 'Connection refused' },
            }),
        });

        expect(await screen.findByRole('region', { name: 'Route' })).toHaveTextContent('No route matches this request.');
        expect(region('Context')).toHaveTextContent('Bypass is on until');
        expect(region('Context')).toHaveTextContent('The scale factor is off.');
        expect(region('Context')).toHaveTextContent('The counters could not be read: Connection refused');
    });
});

describe('the Explain page\'s errors', () => {
    it('AC-5: shows each field\'s message under its field', async () => {
        await explain({
            status: 422,
            body: { message: 'The ip field must be a valid IP address.', errors: { ip: ['The ip field must be a valid IP address.'], 'headers.1': ['Each header is "Name: value".'] } },
        });

        await waitFor(() => expect(screen.getByLabelText('IP')).toHaveAccessibleDescription('The ip field must be a valid IP address.'));
        expect(screen.getByLabelText('Headers')).toHaveAccessibleDescription('Each header is "Name: value".');
        expect(screen.queryByRole('region', { name: 'Decision' })).not.toBeInTheDocument();
    });

    it('AC-5: shows a message about the request as a whole above the result', async () => {
        await explain({ status: 422, body: { message: 'No user 99 for the guard api.', errors: { request: ['No user 99 for the guard api.'] } } });

        expect(await screen.findByText('No user 99 for the guard api.')).toBeInTheDocument();
    });
});

describe('the Explain page', () => {
    it('AC-6: is the page at /explain, loaded on demand, and a viewer can explain a request', async () => {
        const sent = mockApi(viewer({ 'POST explain': { body: explanationOf() } }));
        renderAt(<Pages />, '/explain');

        expect(await screen.findByRole('heading', { level: 1, name: 'Explain' }, { timeout: 5_000 })).toBeInTheDocument();
        await userEvent.type(screen.getByLabelText('Path'), '/api/orders');
        await userEvent.click(screen.getByRole('button', { name: 'Explain' }));

        await screen.findByRole('region', { name: 'Decision' });
        expect(posts(sent)).toHaveLength(1);
        expect(screen.getAllByRole('button').map((button) => button.textContent)).toEqual(['Explain']);
    });

    it('AC-6: offers a manager no control that changes anything either', async () => {
        await explain({ body: explanationOf() }, manager);

        await screen.findByRole('region', { name: 'Decision' });

        expect(screen.getAllByRole('button').map((button) => button.textContent)).toEqual(['Explain']);
    });
});
