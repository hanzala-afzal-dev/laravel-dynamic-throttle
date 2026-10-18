import { execFileSync } from 'node:child_process';
import { test as base, expect, type APIRequestContext, type APIResponse, type Locator, type Page } from '@playwright/test';
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

/** A rule made for one test, its slug, and the path it limits there. */
export type TestRule = Rule & { slug: string; path: string };

/** Bypass and the scale factor, as GET api/settings answers them. */
export type Settings = {
    bypass: { on: boolean; until: string | null };
    scale: { factor: string | null; until: string | null };
};

/** The dashboard's pages: the sidebar's label, the path and the heading. */
export const PAGES = [
    { label: 'Overview', path: '/dynamic-throttle', heading: 'Overview' },
    { label: 'Rules', path: '/dynamic-throttle/rules', heading: 'Rules' },
    { label: 'Exemptions', path: '/dynamic-throttle/exemptions', heading: 'Exemptions' },
    { label: 'Activity', path: '/dynamic-throttle/activity', heading: 'Activity' },
    { label: 'Explain', path: '/dynamic-throttle/explain', heading: 'Explain' },
    { label: 'Audit log', path: '/dynamic-throttle/audit', heading: 'Audit log' },
    { label: 'Settings', path: '/dynamic-throttle/settings', heading: 'Settings' },
];

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

/** Rules a test makes, each on a path of its own, deleted when the test ends. */
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

        return { ...rule, slug, path: `/e2e/${slug}` };
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

/** An exemption as the dashboard's JSON API answers it. */
export type Exemption = {
    id: number;
    rule_id: number | null;
    subject: string;
    value: string;
    reason: string | null;
    expires_at: string | null;
    active: boolean;
};

/**
 * Exemptions a test makes, deleted when it ends. Those it adds through the dashboard are
 * found by its case id, which their reason starts with: one left on every rule would
 * free the client of every later test.
 */
export class TestExemptions {
    private readonly made: number[] = [];

    constructor(
        private readonly api: DashboardApi,
        private readonly caseId: string,
    ) {}

    async create(attributes: { subject?: string; value: string; rule_id?: number | null; reason?: string; expires_at?: string | null; confirm?: boolean }): Promise<Exemption> {
        const { exemption } = await this.api.json<{ exemption: Exemption }>('POST', 'exemptions', {
            subject: 'ip',
            rule_id: null,
            expires_at: null,
            ...attributes,
            reason: `${this.caseId} ${attributes.reason ?? 'test'}`,
        });
        this.made.push(exemption.id);

        return exemption;
    }

    async deleteAll(): Promise<void> {
        // Without a state, the list holds the active and the ended ones.
        const listed = await this.api.json<{ data: Exemption[] }>('GET', 'exemptions');
        const ids = new Set([...this.made, ...listed.data.filter((exemption) => exemption.reason?.startsWith(`${this.caseId} `)).map((exemption) => exemption.id)]);
        for (const id of ids) {
            await this.api.send('DELETE', `exemptions/${id}`);
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

/** A test's case id, the start of its title: `RUL-07` for `RUL-07: …`. */
export function caseIdOf(title: string): string {
    return title.split(':')[0] ?? title;
}

/** A card of the dashboard, found by its heading. */
export function card(page: Page, title: string): Locator {
    return page.locator('[data-slot="card"]').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
}

/** The banner every page shows while bypass or a scale factor is on. */
export function emergencyBanner(page: Page): Locator {
    return page.getByRole('alert').filter({ hasText: 'An emergency setting is on' });
}

/** A number as the dashboard writes it in en-US, such as 12,345. */
export function numberIn(text: string | null): number {
    const match = /-?[\d,]+(\.\d+)?/.exec(text ?? '');
    if (match === null) {
        throw new Error(`No number in "${text}".`);
    }

    return Number(match[0].replaceAll(',', ''));
}

type Fixtures = {
    api: DashboardApi;
    rules: TestRules;
    exemptions: TestExemptions;
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

    exemptions: async ({ api }, use, testInfo) => {
        const exemptions = new TestExemptions(api, caseIdOf(testInfo.title));
        await use(exemptions);
        await exemptions.deleteAll();
    },

    traffic: async ({ request }, use) => {
        await use(new Traffic(request));
    },

    /**
     * Fails the test when its page logs a console error, throws, breaks the Content
     * Security Policy, or gets a server error from the dashboard.
     */
    guard: [
        async ({ page }, use) => {
            const problems: string[] = [];
            page.on('console', (message) => {
                // The browser's own log of a response or a failed request, such as a 409 a test expects or a
                // request it aborts, is no error of the page. Server errors are caught by their status below.
                if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
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

    /** Leaves bypass and scaling off after every test, even one that failed half-way. */
    calm: [
        async ({ api }, use) => {
            await use();
            await api.send('PUT', 'settings/bypass', { state: 'off' });
            await api.send('PUT', 'settings/scale', { factor: 'off' });
        },
        { auto: true },
    ],
});
