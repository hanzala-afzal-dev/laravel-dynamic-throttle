import { defineConfig, devices } from '@playwright/test';

// The browser smoke test (SPEC-036): Chromium against the Workbench app on Redis.
// Outside CI, a server that already answers at the address is used as it is, such
// as the Docker demo (docker compose up -d workbench).
const ci = process.env.CI !== undefined && process.env.CI !== '';
const port = process.env.SMOKE_PORT ?? '8000';
const baseURL = (process.env.SMOKE_URL ?? `http://127.0.0.1:${port}`).replace(/\/+$/, '');

export default defineConfig({
    testDir: '.',
    forbidOnly: ci,
    // A flaky smoke test is a bug to fix (decision 10).
    retries: 0,
    workers: 1,
    timeout: 90_000,
    outputDir: 'test-results',
    reporter: ci ? [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]] : [['list']],
    use: {
        baseURL,
        locale: 'en-US',
        timezoneId: 'UTC',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        // A Chromium of its own, where Playwright cannot install the one it expects.
        launchOptions: process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {},
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: {
        command: `bash e2e/serve.sh ${port}`,
        cwd: '..',
        url: `${baseURL}/dynamic-throttle`,
        reuseExistingServer: !ci,
        timeout: 120_000,
        stdout: 'ignore',
        stderr: 'pipe',
    },
});
