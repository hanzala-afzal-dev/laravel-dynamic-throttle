import { defineConfig } from '@playwright/test';
import { baseURL, port, serverEnvironment } from './e2e/support/environment';

// The dashboard's browser tests: Chromium, against the Workbench app on Redis.
export default defineConfig({
    testDir: './e2e',
    // One worker: bypass and the scale factor are shared by every test.
    workers: 1,
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    timeout: 30_000,
    expect: { timeout: 10_000 },
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : [['list']],
    use: {
        baseURL,
        locale: 'en-US',
        timezoneId: 'UTC',
        viewport: { width: 1280, height: 800 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        launchOptions: {
            // A Chromium already on the machine, for one that cannot download Playwright's.
            executablePath: process.env.E2E_CHROMIUM || undefined,
        },
    },
    projects: [{ name: 'chromium' }],
    webServer: {
        // A fresh database each run, then the server, until the run ends.
        command: [
            'node e2e/support/redis-check.mjs',
            'php vendor/bin/testbench workbench:build --ansi',
            `php vendor/bin/testbench serve --host=127.0.0.1 --port=${port} --no-reload`,
        ].join(' && '),
        url: `${baseURL}/dynamic-throttle`,
        env: serverEnvironment() as Record<string, string>,
        // Always this run's own server, so its prefix and database are this run's.
        reuseExistingServer: false,
        timeout: 120_000,
        stdout: 'ignore',
        stderr: 'pipe',
    },
});
