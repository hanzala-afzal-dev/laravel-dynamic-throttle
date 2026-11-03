import { afterEach, describe, expect, it, vi } from 'vitest';
import { openLink, takeLinkToken } from '@/lib/link';

/* SPEC-034 AC-21 and AC-22: the link's token, taken from the address before anything else, and posted on "Continue". */

const TOKEN = 'k3J9xQ2mN8vR4tY7wE1aS5dF6gH0jK2lZ9xC3vB7n_-';

afterEach(() => {
    window.history.replaceState(null, '', '/');
});

describe('takeLinkToken', () => {
    it('AC-21: takes the token from the link\'s address, and removes it from the address bar and the history entry', () => {
        window.history.replaceState(null, '', `/dynamic-throttle/link#${TOKEN}`);
        const entries = window.history.length;

        expect(takeLinkToken('/dynamic-throttle')).toBe(TOKEN);
        expect(window.location.href).not.toContain(TOKEN);
        expect(window.location.hash).toBe('');
        expect(window.location.pathname).toBe('/dynamic-throttle/link');
        // Replaced, not pushed: no history entry keeps the token.
        expect(window.history.length).toBe(entries);
    });

    it('AC-22: gives nothing when the address has no token, such as after a reload', () => {
        window.history.replaceState(null, '', '/dynamic-throttle/link');

        expect(takeLinkToken('/dynamic-throttle')).toBeNull();
    });

    it('AC-21: leaves any other page\'s address alone', () => {
        window.history.replaceState(null, '', '/dynamic-throttle/rules#section');

        expect(takeLinkToken('/dynamic-throttle')).toBeNull();
        expect(window.location.hash).toBe('#section');
    });
});

describe('openLink', () => {
    it('AC-22: posts the token in the body, with the CSRF token, and resolves on 204', async () => {
        const fetch = vi.fn(async () => new Response(null, { status: 204 }));
        vi.stubGlobal('fetch', fetch);

        await expect(openLink(TOKEN)).resolves.toBeUndefined();

        expect(fetch).toHaveBeenCalledTimes(1);
        const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/dynamic-throttle/api/link');
        expect(init.method).toBe('POST');
        expect(JSON.parse(String(init.body))).toEqual({ token: TOKEN });
        expect((init.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('a-csrf-token');
        expect(url).not.toContain(TOKEN);
    });

    it('AC-22: rejects with the server\'s own message for a link that cannot be used', async () => {
        const message = 'This link is not valid: it was already used, it was revoked, or it has expired. Ask for a new one.';
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message }), { status: 403, headers: { 'Content-Type': 'application/json' } })));

        await expect(openLink(TOKEN)).rejects.toThrow(message);
    });

    it('AC-22: rejects with the server\'s message for a malformed token', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'The token field format is invalid.', errors: { token: ['The token field format is invalid.'] } }), { status: 422 })));

        await expect(openLink('short')).rejects.toThrow('The token field format is invalid.');
    });

    it('AC-22: rejects with a message a person can act on when the server cannot be reached', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => {
            throw new TypeError('Failed to fetch');
        }));

        await expect(openLink(TOKEN)).rejects.toThrow('The server could not be reached: check the connection, then try again.');
    });
});
