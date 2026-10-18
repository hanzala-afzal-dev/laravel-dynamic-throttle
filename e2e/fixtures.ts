import { execFileSync } from 'node:child_process';
import { test as base, expect, type APIRequestContext, type APIResponse } from '@playwright/test';
import { dashboard, root, serverEnvironment } from './support/environment';

export { expect };

/** A rule as the dashboard's JSON API answers it. */
export type Rule = {
    id: number;
    name: string;
    method: string;
    pattern: string;
    scope: string;
    scope_parameter: string | null;
    max_attempts: number;
    window_seconds: number;
    priority: number;
    mode: 'enforce' | 'dry_run';
    enabled: boolean;
};

/** A rule made for one test, and the path it limits there. */
export type TestRule = Rule & { path: string };

/**
 * The dashboard's JSON API, as a guest, who manages in the Workbench app. It reads the
 * CSRF token from the page once, as the dashboard's own client does.
 */
export class DashboardApi {
    private constructor(
        private readonly request: APIRequestContext,
        private readonly token: string,
    ) {}

    static async open(request: APIRequestContext): Promise<DashboardApi> {
        const html = await (await request.get(`${dashboard}/`)).text();
        const token = /<meta name="csrf-token" content="([^"]*)"/.exec(html)?.[1];
        if (token === undefined) {
            throw new Error('The dashboard\'s page has no CSRF token.');
        }

        return new DashboardApi(request, token);
    }

    /** Sends a request to `api/{path}`, and answers whatever the server answered. */
    send(method: string, path: string, body?: unknown): Promise<APIResponse> {
        return this.request.fetch(`${dashboard}/api/${path}`, {
            method,
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.token },
            data: body,
        });
    }

    /** The JSON a request answers, or an error that says what the server answered instead. */
    async json<T = Record<string, unknown>>(method: string, path: string, body?: unknown): Promise<T> {
        const response = await this.send(method, path, body);
        if (!response.ok()) {
            throw new Error(`${method} api/${path} answered ${response.status()}: ${await response.text()}`);
        }

        return (await response.json()) as T;
    }
}

/** Rules a test makes, each on a path of its own, deleted when the test ends (decision 4). */
export class TestRules {
    private readonly made: number[] = [];

    constructor(private readonly api: DashboardApi) {}

    /**
     * Makes an enforcing rule of 2 requests a minute per IP on `e2e/{case}-{random}`,
     * named with the test's case id, with any attributes the test overrides.
     */
    async create(caseId: string, attributes: Partial<Rule> & { response_override?: unknown } = {}): Promise<TestRule> {
        const slug = `${caseId.toLowerCase()}-${Math.random().toString(36).slice(2, 8)}`;
        const { rule } = await this.api.json<{ rule: Rule }>('POST', 'rules', {
            name: `${caseId} ${slug}`,
            pattern: `e2e/${slug}`,
            scope: 'ip',
            max_attempts: 2,
            window_seconds: 60,
            mode: 'enforce',
            ...attributes,
        });
        this.made.push(rule.id);

        return { ...rule, path: `/e2e/${slug}` };
    }

    /** A rule the test made through the dashboard, deleted with the others. */
    track(id: number): void {
        this.made.push(id);
    }

    async deleteAll(): Promise<void> {
        for (const id of this.made) {
            // A rule the test deleted itself answers 404, which is fine.
            await this.api.send('DELETE', `rules/${id}`, { confirm: true });
        }
    }
}

/** Requests to the Workbench app's routes, as a client of the app would send them. */
export class Traffic {
    constructor(private readonly request: APIRequestContext) {}

    get(path: string): Promise<APIResponse> {
        return this.request.get(path, { headers: { Accept: 'application/json' } });
    }

    /** The statuses of `count` requests, one after the other. */
    async statuses(path: string, count: number): Promise<number[]> {
        const statuses: number[] = [];
        for (let sent = 0; sent < count; sent++) {
            statuses.push((await this.get(path)).status());
        }

        return statuses;
    }
}

/** Runs an Artisan command in the Workbench app, with the server's environment, and answers its output. */
export function artisan(...args: string[]): string {
    return execFileSync('php', ['vendor/bin/testbench', ...args, '--no-interaction'], {
        cwd: root,
        env: serverEnvironment(),
        encoding: 'utf8',
    });
}

type Fixtures = {
    api: DashboardApi;
    rules: TestRules;
    traffic: Traffic;
    guard: void;
    calm: void;
};

export const test = base.extend<Fixtures>({
    api: async ({ request }, use) => {
        await use(await DashboardApi.open(request));
    },

    rules: async ({ api }, use) => {
        const rules = new TestRules(api);
        await use(rules);
        await rules.deleteAll();
    },

    traffic: async ({ request }, use) => {
        await use(new Traffic(request));
    },

    /**
     * Fails the test when its page logs a console error, throws, breaks the Content
     * Security Policy, or gets a server error from the dashboard (AC-2, decision 5).
     */
    guard: [
        async ({ page }, use) => {
            const problems: string[] = [];
            page.on('console', (message) => {
                // A 4xx a test expects, such as a 409 or a 422, is the browser's log of a response, not an error.
                if (message.type() === 'error' && !/^Failed to load resource: the server responded with a status of 4\d\d/.test(message.text())) {
                    problems.push(`console error: ${message.text()}`);
                }
            });
            page.on('pageerror', (error) => problems.push(`uncaught error: ${error.message}`));
            page.on('response', (response) => {
                if (response.status() >= 500 && response.url().startsWith(dashboard)) {
                    problems.push(`${response.status()} from ${response.url()}`);
                }
            });
            await page.addInitScript(() => {
                document.addEventListener('securitypolicyviolation', (event) => {
                    console.error(`CSP violation: ${event.violatedDirective} blocked ${event.blockedURI || 'an inline resource'}`);
                });
            });
            await use();
            expect(problems, 'the page logged no error, threw nothing, and broke no CSP rule').toEqual([]);
        },
        { auto: true },
    ],

    /** Leaves bypass and scaling off after every test, even one that failed half-way (AC-5). */
    calm: [
        async ({ api }, use) => {
            await use();
            await api.send('PUT', 'settings/bypass', { state: 'off' });
            await api.send('PUT', 'settings/scale', { factor: 'off' });
        },
        { auto: true },
    ],
});
