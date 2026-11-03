import { test as base, expect } from '@playwright/test';

/** Chromium's console line for an error answer, which the response handler names better. */
const ERROR_ANSWER = 'Failed to load resource: the server responded with a status of';

/**
 * The page guard (SPEC-036 decision 6): a test fails when its page logs a console
 * error, throws, or gets an error answer from the dashboard. Chromium logs a Content
 * Security Policy violation as a console error, so a violation fails it too.
 */
export const test = base.extend({
    page: async ({ page }, use) => {
        const problems: string[] = [];

        page.on('console', (message) => {
            if (message.type() === 'error' && !message.text().startsWith(ERROR_ANSWER)) {
                problems.push(`Console error: ${message.text()}`);
            }
        });
        page.on('pageerror', (error) => problems.push(`Uncaught: ${error.message}`));
        page.on('response', (response) => {
            const status = response.status();

            if (status < 400 || !new URL(response.url()).pathname.startsWith('/dynamic-throttle')) {
                return;
            }

            const problem = `${status} from ${response.request().method()} ${response.url()}`;
            problems.push(
                status === 429
                    ? `${problem}: the dashboard's own limit, 120 requests a minute. Wait a minute, or serve with bash e2e/serve.sh, which turns it off.`
                    : problem,
            );
        });

        await use(page);

        expect(problems, 'The page guard saw problems').toEqual([]);
    },
});

export { expect };
