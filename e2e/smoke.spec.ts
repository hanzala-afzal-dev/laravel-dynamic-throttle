import { expect, test } from './guard';

// The browser smoke test (SPEC-036). The Workbench app lets its guest manage, so
// the tests sign in as nobody.

test('AC-2: a rule made in the dashboard limits its path, and Activity shows the block', async ({ page, request }) => {
    // A name and a path of their own, so the test can run again on the same server (decision 4).
    const run = Date.now().toString(36);
    const name = `Smoke test ${run}`;
    const path = `api/smoke/${run}`;

    await page.goto('/dynamic-throttle/rules');
    await page.getByRole('link', { name: 'New rule' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'New rule' })).toBeVisible();

    await page.getByLabel('Name', { exact: true }).fill(name);
    await page.getByLabel('Pattern', { exact: true }).fill(path);
    await page.getByLabel('Per', { exact: true }).selectOption('ip');
    await page.getByLabel('Attempts', { exact: true }).fill('2');
    await page.getByLabel('Window', { exact: true }).fill('1');
    await page.getByLabel('Window unit', { exact: true }).selectOption('minutes');
    await page.getByLabel('Mode', { exact: true }).selectOption('enforce');
    await page.getByRole('button', { name: 'Create rule' }).click();
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();

    // The rule applies once the manifest refreshes, 2 seconds by default (decision 5).
    await expect
        .poll(async () => (await request.get(`/${path}`)).status(), {
            message: `GET /${path} never got a 429`,
            timeout: 20_000,
            intervals: [500],
        })
        .toBe(429);

    await page.getByRole('navigation').getByRole('link', { name: 'Activity' }).click();
    const feed = page.getByRole('region', { name: 'Live feed' });
    const noFeed = feed.getByText('This counter store keeps no live feed');
    const row = feed.getByRole('row').filter({ has: page.getByRole('link', { name, exact: true }) });

    await expect(row.first().or(noFeed)).toBeVisible({ timeout: 15_000 });
    await expect(noFeed, 'The smoke test needs Redis: the server keeps no live feed (docker compose up -d workbench, or bash e2e/serve.sh)').toHaveCount(0);
    await expect(row.first()).toContainText('Blocked');

    // Delete the rule from its page, through its dialog.
    await row.first().getByRole('link', { name, exact: true }).click();
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
    await page.getByRole('button', { name: 'Delete' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Rules' })).toBeVisible();
    await expect(page.getByText(name, { exact: true })).toHaveCount(0);
});

test('AC-3: every page of the sidebar opens with its heading', async ({ page }) => {
    await page.goto('/dynamic-throttle');

    for (const label of ['Overview', 'Rules', 'Exemptions', 'Activity', 'Explain', 'Audit log', 'Settings']) {
        await page.getByRole('navigation').getByRole('link', { name: label, exact: true }).click();
        await expect(page.getByRole('heading', { level: 1, name: label, exact: true })).toBeVisible();
        // Each page's first requests, so the guard sees their answers.
        await page.waitForLoadState('networkidle');
    }
});
