import { artisan, emergencyBanner, expect, PAGES, test } from './fixtures';

// The dashboard's frame: its pages, the sidebar, the header, the theme and its headers.

test('SHL-01: every page opens, with its heading, and no error or CSP violation', async ({ page }) => {
    for (const { path, heading } of PAGES) {
        await page.goto(path);
        await expect(page.getByRole('heading', { level: 1, name: heading, exact: true })).toBeVisible();
    }
});

test('SHL-02: the sidebar lists the seven pages, in order, and marks the current one, on its sub-pages too', async ({ page, rules }) => {
    await page.goto('/dynamic-throttle');
    const nav = page.getByRole('navigation', { name: 'Pages' });
    await expect(nav.getByRole('link')).toHaveText(PAGES.map(({ label }) => label));

    for (const { label, heading } of PAGES) {
        await nav.getByRole('link', { name: label, exact: true }).click();
        await expect(page.getByRole('heading', { level: 1, name: heading, exact: true })).toBeVisible();
        await expect(nav.getByRole('link', { name: label, exact: true })).toHaveAttribute('aria-current', 'page');
        await expect(nav.locator('[aria-current="page"]')).toHaveCount(1);
    }

    const rule = await rules.create('SHL-02');
    await page.goto(`/dynamic-throttle/rules/${rule.id}`);
    await expect(nav.getByRole('link', { name: 'Rules', exact: true })).toHaveAttribute('aria-current', 'page');
});

test('SHL-03: a path the dashboard does not have answers 404, and leaves the app\'s other paths alone', async ({ page }) => {
    // The shell answers its pages' paths only, so an app route under the dashboard's path stays the app's (SPEC-018 AC-20).
    const response = await page.goto('/dynamic-throttle/nope');
    expect(response!.status()).toBe(404);
    await expect(page.locator('#dynamic-throttle')).toHaveCount(0);
});

test('SHL-04: the header shows the environment and the role', async ({ page }) => {
    await page.goto('/dynamic-throttle');
    const header = page.getByRole('banner');
    await expect(header.getByText('local', { exact: true })).toBeVisible();
    // A guest manages in the Workbench app.
    await expect(header.getByText('Manager', { exact: true })).toBeVisible();
});

test('SHL-05: the theme button goes light, dark, then system, and the choice stays after a reload', async ({ page }) => {
    await page.goto('/dynamic-throttle');
    const html = page.locator('html');
    const button = page.getByRole('button', { name: /^Theme: / });
    await expect(button).toHaveAccessibleName('Theme: system');

    await button.click();
    await expect(button).toHaveAccessibleName('Theme: light');
    await expect(html).not.toHaveClass(/\bdark\b/);

    await button.click();
    await expect(button).toHaveAccessibleName('Theme: dark');
    await expect(html).toHaveClass(/\bdark\b/);

    await page.reload();
    await expect(button).toHaveAccessibleName('Theme: dark');
    await expect(html).toHaveClass(/\bdark\b/);

    await button.click();
    await expect(button).toHaveAccessibleName('Theme: system');
    await expect(html).not.toHaveClass(/\bdark\b/);
});

test('SHL-06: a deep link opens its page directly, also after a reload', async ({ page, rules }) => {
    const rule = await rules.create('SHL-06');
    await page.goto(`/dynamic-throttle/rules/${rule.id}/edit`);
    await expect(page.getByRole('heading', { level: 1, name: `Edit ${rule.name}` })).toBeVisible();

    await page.reload();
    await expect(page.getByRole('heading', { level: 1, name: `Edit ${rule.name}` })).toBeVisible();
});

test('SHL-07: the page sends its security headers, with a new nonce on every load', async ({ page }) => {
    const nonces: string[] = [];
    for (let load = 0; load < 2; load++) {
        const response = await page.goto('/dynamic-throttle');
        const headers = response!.headers();
        const nonce = await page.locator('meta[name="csp-nonce"]').getAttribute('content');
        expect(nonce).toBeTruthy();
        expect(headers['content-security-policy']).toContain(`'nonce-${nonce}'`);
        expect(headers['content-security-policy']).toContain("frame-ancestors 'none'");
        expect(headers['x-frame-options']).toBe('DENY');
        expect(headers['cache-control']).toContain('no-store');
        expect(headers['x-content-type-options']).toBe('nosniff');
        expect(headers['referrer-policy']).toBe('same-origin');
        nonces.push(nonce!);
    }
    expect(nonces[0]).not.toBe(nonces[1]);
});

test('SHL-09: without JavaScript, the page says the dashboard needs it', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto('/dynamic-throttle');
    // Playwright's text locators skip <noscript>, so the test reads the text the page renders.
    expect(await page.locator('body').innerText()).toContain('The dashboard needs JavaScript.');
    await context.close();
});

test('SHL-10: a change made from the CLI shows on an open page, without a reload', async ({ page }) => {
    await page.goto('/dynamic-throttle');
    await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible();
    await expect(emergencyBanner(page)).toHaveCount(0);

    artisan('dynamic-throttle:bypass', 'on', '--for=5m');

    // The settings poll every 5 seconds.
    await expect(emergencyBanner(page)).toBeVisible({ timeout: 12_000 });
});

test('LIM-10: a rule on every path limits the app, but never the dashboard', async ({ page, rules, traffic }) => {
    await rules.create('LIM-10', { method: '*', pattern: '*', max_attempts: 1 });
    expect(await traffic.statuses('/e2e/lim-10-anything', 2)).toEqual([200, 429]);

    const limited: string[] = [];
    page.on('response', (response) => {
        if (response.status() === 429 || response.headers()['x-ratelimit-limit'] === '1') {
            limited.push(response.url());
        }
    });
    for (const { path, heading } of PAGES) {
        await page.goto(path);
        await expect(page.getByRole('heading', { level: 1, name: heading, exact: true })).toBeVisible();
    }
    expect(limited).toEqual([]);
});
