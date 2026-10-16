import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, type RenderResult } from '@testing-library/react';
import type { ReactElement } from 'react';
import { vi } from 'vitest';
import { Router } from 'wouter';
import { memoryLocation } from 'wouter/memory-location';

/** A JSON answer of the mocked API. */
export type Answer = { status?: number; body: unknown };

/** An answer, or a function of the request's body that gives one. */
export type Answers = Record<string, Answer | ((body: unknown) => Answer)>;

/** The requests the mocked API received, as [method, path, body]. */
export type Sent = [string, string, unknown][];

/**
 * Mocks fetch with answers by "METHOD path", the path after /dynamic-throttle/api/,
 * such as "GET me". Unknown requests answer 404.
 */
export function mockApi(answers: Answers): Sent {
    const sent: Sent = [];

    vi.stubGlobal('fetch', vi.fn(async (input: string, init?: RequestInit) => {
        const method = init?.method ?? 'GET';
        const path = input.replace('/dynamic-throttle/api/', '');
        const body = typeof init?.body === 'string' ? JSON.parse(init.body) : undefined;
        sent.push([method, path, body]);
        const found = answers[`${method} ${path}`];
        const answer = typeof found === 'function' ? found(body) : (found ?? { status: 404, body: { message: 'Not found.' } });

        return new Response(JSON.stringify(answer.body), { status: answer.status ?? 200, headers: { 'Content-Type': 'application/json' } });
    }));

    return sent;
}

/** The answers every page needs: a manager, and no emergency setting on. */
export function manager(overrides: Answers = {}): Answers {
    return {
        'GET me': { body: { can: { view: true, manage: true }, environment: 'production' } },
        'GET settings': { body: { bypass: { on: false, until: null }, scale: { factor: null, until: null } } },
        ...overrides,
    };
}

/** As manager(), for a viewer. */
export function viewer(overrides: Answers = {}): Answers {
    return manager({ 'GET me': { body: { can: { view: true, manage: false }, environment: 'local' } }, ...overrides });
}

/**
 * Renders inside the dashboard's providers, at a path of the dashboard, which may
 * hold a search string, with a client that does not retry.
 */
export function renderAt(ui: ReactElement, path = '/'): RenderResult & { client: QueryClient; history: string[] } {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    // Recorded, so a test can see where the page navigated, its search string too.
    const { hook, history } = memoryLocation({ path, record: true });

    const result = render(
        <QueryClientProvider client={client}>
            <Router hook={hook} base="">
                {ui}
            </Router>
        </QueryClientProvider>,
    );

    return { ...result, client, history };
}
