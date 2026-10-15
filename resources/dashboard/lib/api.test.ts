import { describe, expect, it, vi } from 'vitest';
import { api, ApiError } from '@/lib/api';

/* SPEC-020 AC-10: the API client. */

function answer(status: number, body: unknown) {
    const fetch = vi.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetch);

    return fetch;
}

async function failure(promise: Promise<unknown>): Promise<ApiError> {
    try {
        await promise;
    } catch (error) {
        if (error instanceof ApiError) {
            return error;
        }
    }

    throw new Error('The request did not fail with an ApiError.');
}

describe('the API client', () => {
    it('AC-10: sends JSON with the CSRF token, to the dashboard\'s API', async () => {
        const fetch = answer(200, { ok: true });

        expect(await api('PATCH', 'rules/12', { max_attempts: 5 })).toEqual({ ok: true });
        expect(fetch).toHaveBeenCalledWith('/dynamic-throttle/api/rules/12', {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': 'a-csrf-token',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: '{"max_attempts":5}',
        });
    });

    it.each([
        [403, { message: 'This action is unauthorized.' }, 'You may not do this.'],
        [419, { message: 'CSRF token mismatch.' }, 'Your session expired: reload the page.'],
        [429, { message: 'Too Many Attempts.' }, 'Too many requests: wait a moment.'],
        [503, { message: 'The dynamic_throttle_audit_log table does not exist.' }, 'The dynamic_throttle_audit_log table does not exist.'],
        [409, { message: 'Bypass lifts every limit until 2027-01-15 14:00 UTC.' }, 'Bypass lifts every limit until 2027-01-15 14:00 UTC.'],
        [500, { message: 'Server Error' }, 'The server failed: Server Error'],
    ])('AC-10: gives a %i its message', async (status, body, message) => {
        answer(status, body);

        const error = await failure(api('GET', 'settings'));

        expect([error.status, error.message, error.needsConfirmation]).toEqual([status, message, status === 409]);
    });

    it('AC-10: keeps a 422\'s messages under each field', async () => {
        answer(422, { message: 'The max attempts field must be at least 1.', errors: { max_attempts: ['The max attempts field must be at least 1.'] } });

        const error = await failure(api('POST', 'rules', { max_attempts: 0 }));

        expect([error.status, error.message, error.fields]).toEqual([
            422,
            'The max attempts field must be at least 1.',
            { max_attempts: ['The max attempts field must be at least 1.'] },
        ]);
    });

    it.each([
        ['a login page', () => new Response('<!doctype html><title>Log in</title>', { status: 200, headers: { 'Content-Type': 'text/html' } })],
        ['JSON reached through a redirect', () => redirected(new Response('{"bypass":null}', { status: 200, headers: { 'Content-Type': 'application/json' } }))],
    ])('AC-17: refuses a 2xx that is %s, rather than return it', async (_, response) => {
        vi.stubGlobal('fetch', vi.fn(async () => response()));

        const error = await failure(api('GET', 'settings'));

        expect([error.status, error.message]).toEqual([200, 'The server answered with another page, such as a login: reload the page.']);
    });
});

/** A response as fetch gives it after following a redirect. */
function redirected(response: Response): Response {
    Object.defineProperty(response, 'redirected', { value: true });

    return response;
}
