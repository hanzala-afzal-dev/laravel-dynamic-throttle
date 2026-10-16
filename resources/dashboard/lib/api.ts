/**
 * The dashboard's client for its JSON API (SPEC-017, SPEC-020): JSON both ways, the
 * CSRF token from the shell's <meta> tag, and each error status turned into a
 * message a person can act on.
 */

export type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

let base = '';

/** The dashboard's base path, from the shell's data-base attribute. */
export function configureApi(options: { base: string }): void {
    base = options.base;
}

export class ApiError extends Error {
    constructor(
        readonly status: number,
        message: string,
        /** A 422's messages, under each field. */
        readonly fields: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'ApiError';
    }

    /** A dangerous change the API asks to confirm with "confirm": true (SPEC-018). */
    get needsConfirmation(): boolean {
        return this.status === 409;
    }
}

/** Statuses whose server message does not help the person reading it. */
const MESSAGES: Partial<Record<number, string>> = {
    403: 'You may not do this.',
    419: 'Your session expired: reload the page.',
    429: 'Too many requests: wait a moment.',
};

/** A body that does not parse as JSON. */
const NOT_JSON = Symbol('not JSON');

export async function api<T>(method: Method, path: string, body?: unknown): Promise<T> {
    let response: Response;

    try {
        response = await fetch(`${base}/api/${path}`, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        });
    } catch {
        // Offline, a reset connection, or a redirect the browser refused to follow (SPEC-021 AC-14).
        throw new ApiError(0, 'The server could not be reached: check the connection, then try again.');
    }
    const data: unknown = await response.json().catch(() => NOT_JSON);

    // The API answers JSON and never redirects (SPEC-017), so another page, such as an app's login, is no answer.
    if (response.ok && (response.redirected || data === NOT_JSON)) {
        throw new ApiError(response.status, 'The server answered with another page, such as a login: reload the page.');
    }

    if (response.ok) {
        return data as T;
    }

    const message = messageOf(data, response.status);
    const shown = MESSAGES[response.status] ?? (response.status >= 500 && response.status !== 503 ? `The server failed: ${message}` : message);

    throw new ApiError(response.status, shown, fieldsOf(data));
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function messageOf(data: unknown, status: number): string {
    if (typeof data === 'object' && data !== null && 'message' in data && typeof data.message === 'string') {
        return data.message;
    }

    return `The request failed with ${status}.`;
}

function fieldsOf(data: unknown): Record<string, string[]> {
    if (typeof data !== 'object' || data === null || !('errors' in data) || typeof data.errors !== 'object' || data.errors === null) {
        return {};
    }

    return Object.fromEntries(
        Object.entries(data.errors).map(([field, messages]) => [field, Array.isArray(messages) ? messages.filter((m): m is string => typeof m === 'string') : []]),
    );
}
