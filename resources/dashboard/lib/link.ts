import { ApiError, csrfToken, dashboardBase } from '@/lib/api';

/**
 * A dashboard link (SPEC-034): https://example.com/dynamic-throttle/link#<token>.
 * Browsers never send the part after #, so no server log or Referer header holds
 * the token, and the page takes it out of the address before anything else runs.
 */

/** For the link's page without a token in its address, such as after a reload (AC-22). */
export const NO_TOKEN = 'This address has no link in it. Open the link you were sent again.';

/**
 * Takes the token from the link's page's address, and removes it from the address bar
 * and from the history entry, which it replaces (AC-21). Any other page's address is
 * left alone.
 */
export function takeLinkToken(base: string): string | null {
    const { pathname, search, hash } = window.location;

    if (pathname !== `${base}/link` || hash.length < 2) {
        return null;
    }

    window.history.replaceState(window.history.state, '', `${pathname}${search}`);

    return hash.slice(1);
}

/**
 * Uses the link: posts its token in the body, never the address, with the session's
 * CSRF token (AC-22). Resolves once the server has used it, and rejects with the
 * server's own message otherwise, which says what to do.
 */
export async function openLink(token: string): Promise<void> {
    let response: Response;

    try {
        response = await fetch(`${dashboardBase()}/api/link`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ token }),
        });
    } catch {
        throw new ApiError(0, 'The server could not be reached: check the connection, then try again.');
    }

    if (response.status === 204) {
        return;
    }

    if (response.status === 419) {
        // The page took the token out of its address, so a reload cannot help: the link can.
        throw new ApiError(419, 'Your session expired: open the link you were sent again.');
    }

    const data: unknown = await response.json().catch(() => null);
    const message = typeof data === 'object' && data !== null && 'message' in data && typeof data.message === 'string' ? data.message : `The link could not be used (${response.status}).`;

    throw new ApiError(response.status, message);
}
