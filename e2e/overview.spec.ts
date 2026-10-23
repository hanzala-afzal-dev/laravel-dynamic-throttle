import type { Locator, Page } from '@playwright/test';
import { card, emergencyBanner, expect, numberIn, PAGES, type Settings, test } from './fixtures';

// The Overview (SPEC-021): health, the rules, the last hour, the traffic, the top rules,
// and the emergency controls, which change bypass and the scale factor for every rule.

type HealthReport = { status: 'ok' | 'warning' | 'error' | 'skipped'; checks: { status: string; message: string }[] };

/** A share as the dashboard writes it in en-US, such as 66.7%. */
const share = (part: number, whole: number) => new Intl.NumberFormat('en-US', { style: 'percent', maximumFractionDigits: 1 }).format(whole === 0 ? 0 : part / whole);

/** The time a confirmation names, "until 2026-10-18 14:05 UTC", in milliseconds. */
function untilIn(text: string): number {
    const match = /until (\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}) UTC/.exec(text);
    if (match === null) {
        throw new Error(`No end time in "${text}".`);
    }

    return Date.parse(`${match[1]}T${match[2]}:00Z`);
}

/** Expects a time about `minutes` from now, as a setting's end time, which counts from when it was saved. */
function expectAbout(time: number, minutes: number): void {
    expect(Math.abs(time - (Date.now() + minutes * 60_000)), `about ${minutes} minutes from now`).toBeLessThanOrEqual(2 * 60_000);
}

/** The Rules card's counts, once it shows them. */
async function ruleCounts(rules: Locator): Promise<{ enabled: number; enforcing: number; dryRun: number; disabled: number }> {
    await expect(rules.getByText('enabled', { exact: true })).toBeVisible();
    const text = (await rules.textContent()) ?? '';
    const [, enforcing, dryRun] = /([\d,]+) enforcing, ([\d,]+) dry-run/.exec(text) ?? [];

    return {
        enabled: numberIn(/([\d,]+)\s*enabled/.exec(text)?.[1] ?? null),
        enforcing: numberIn(enforcing ?? null),
        dryRun: numberIn(dryRun ?? null),
        disabled: numberIn(/([\d,]+) disabled/.exec(text)?.[1] ?? null),
    };
}

/** The "Blocks in the last hour" card's blocks and checks, once it shows them. */
async function lastHour(blocks: Locator): Promise<{ blocks: number; checks: number }> {
    const detail = blocks.getByText(/^(No rule checked a request\.|.+ of [\d,]+ checks)$/);
    await expect(detail).toBeVisible();
    const text = (await detail.textContent()) ?? '';

    return {
        blocks: numberIn(await blocks.getByText(/^[\d,]+$/).textContent()),
        checks: text.startsWith('No rule') ? 0 : numberIn(/of ([\d,]+) checks/.exec(text)?.[1] ?? null),
    };
}

/** Opens the Overview, and answers its emergency controls once they show the settings. */
async function emergencyControls(page: Page): Promise<Locator> {
    await page.goto('/dynamic-throttle');
    const controls = card(page, 'Emergency controls');
    await expect(controls.getByRole('heading', { name: 'Bypass', exact: true })).toBeVisible();

    return controls;
}

test('OVW-01: the Health card shows the status, its first three problems with errors first, and links to every check', async ({ page, api }) => {
    await page.goto('/dynamic-throttle');
    const health = card(page, 'Health');
    await expect(health.getByText(/^(Healthy|Warnings|Errors)$/)).toBeVisible();

    const report = await api.json<HealthReport>('GET', 'health');
    const label = { ok: 'Healthy', skipped: 'Healthy', warning: 'Warnings', error: 'Errors' }[report.status];
    await expect(health.getByText(label, { exact: true })).toBeVisible();
    const problems = [
        ...report.checks.filter((check) => check.status === 'error').map((check) => `Error: ${check.message}`),
        ...report.checks.filter((check) => check.status === 'warning').map((check) => `Warning: ${check.message}`),
    ].slice(0, 3);
    await expect(health.getByRole('listitem')).toHaveText(problems);

    await health.getByRole('link', { name: 'See every check' }).click();
    await expect(page).toHaveURL(/\/dynamic-throttle\/settings$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Settings' })).toBeVisible();
});

test('OVW-02: "Check again" asks for the health report again', async ({ page }) => {
    await page.goto('/dynamic-throttle');
    const health = card(page, 'Health');
    await expect(health.getByText(/^(Healthy|Warnings|Errors)$/)).toBeVisible();
    const button = health.getByRole('button', { name: 'Check again' });
    await expect(button).toBeEnabled();

    const asked = page.waitForResponse((response) => new URL(response.url()).pathname === '/dynamic-throttle/api/health');
    await button.click();
    expect((await asked).status()).toBe(200);
    await expect(button).toBeEnabled();
    await expect(health.getByText(/^(Healthy|Warnings|Errors)$/)).toBeVisible();
});

test('OVW-03: the Rules card counts the enabled rules by mode and the disabled ones, and counts a new enforcing rule', async ({ page, api, rules }) => {
    await page.goto('/dynamic-throttle');
    const before = await ruleCounts(card(page, 'Rules'));
    const counted = await api.json<{ enforcing: number; dry_run: number; disabled: number }>('GET', 'rules/counts');
    expect(before).toEqual({ enabled: counted.enforcing + counted.dry_run, enforcing: counted.enforcing, dryRun: counted.dry_run, disabled: counted.disabled });

    await rules.create('OVW-03');
    // The counts refresh every minute, so the page is loaded again.
    await page.reload();
    expect(await ruleCounts(card(page, 'Rules'))).toEqual({ ...before, enabled: before.enabled + 1, enforcing: before.enforcing + 1 });
});

test('OVW-04: "Blocks in the last hour" counts a rule\'s blocks within seconds, with their share of the checks', async ({ page, rules, traffic }) => {
    const rule = await rules.create('OVW-04', { max_attempts: 1 });
    await page.goto('/dynamic-throttle');
    const blocks = card(page, 'Blocks in the last hour');
    const before = await lastHour(blocks);

    expect(await traffic.statuses(rule.path, 4)).toEqual([200, 429, 429, 429]);

    // The last hour polls every 5 seconds.
    const checks = before.checks + 4;
    await expect(blocks.getByText(/^[\d,]+$/)).toHaveText((before.blocks + 3).toLocaleString('en-US'), { timeout: 12_000 });
    await expect(blocks.getByText(`${share(before.blocks + 3, checks)} of ${checks.toLocaleString('en-US')} checks`, { exact: true })).toBeVisible();
});

test('OVW-05: the Traffic card\'s tabs show the hour, the day or the week, and Top rules follows them', async ({ page, rules, traffic }) => {
    const rule = await rules.create('OVW-05', { max_attempts: 1 });
    // The day and the week come from the demo's numbers, the hour from these requests.
    expect(await traffic.statuses(rule.path, 2)).toEqual([200, 429]);

    await page.goto('/dynamic-throttle');
    const periods = card(page, 'Traffic').getByRole('tablist', { name: 'Period' });
    const top = card(page, 'Top rules');
    await expect(periods.getByRole('tab', { name: 'Hour' })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('figure', { name: 'Blocks and would-blocks in the last hour' })).toBeVisible();
    await expect(top.getByText('By blocks, then would-blocks, in the last hour', { exact: true })).toBeVisible();

    for (const period of ['day', 'week']) {
        const tab = periods.getByRole('tab', { name: period === 'day' ? 'Day' : 'Week' });
        await tab.click();
        await expect(tab).toHaveAttribute('aria-selected', 'true');
        await expect(page.getByRole('figure', { name: `Blocks and would-blocks in the last ${period}` })).toBeVisible();
        await expect(top.getByText(`By blocks, then would-blocks, in the last ${period}`, { exact: true })).toBeVisible();
    }
});

test('OVW-06: Top rules lists a rule that blocked, as a link to its page', async ({ page, rules, traffic }) => {
    const rule = await rules.create('OVW-06', { max_attempts: 1 });
    // More blocks than any other test makes, so the rule is among the top five.
    const statuses = await traffic.statuses(rule.path, 11);
    expect(statuses.filter((status) => status === 429)).toHaveLength(10);

    await page.goto('/dynamic-throttle');
    const link = card(page, 'Top rules').getByRole('link', { name: rule.name, exact: true });
    await expect(link).toBeVisible({ timeout: 12_000 });
    await link.click();
    await expect(page).toHaveURL(new RegExp(`/dynamic-throttle/rules/${rule.id}$`));
    await expect(page.getByRole('heading', { level: 1, name: rule.name })).toBeVisible();
});

test('OVW-07: "Turn on bypass" asks to confirm, saying what bypass does and when it ends, and Cancel changes nothing', async ({ page, api }) => {
    const controls = await emergencyControls(page);
    await expect(controls.getByText('Bypass is off.', { exact: true })).toBeVisible();

    await controls.getByLabel('Bypass for').selectOption({ label: '15 minutes' });
    await controls.getByRole('button', { name: 'Turn on bypass' }).click();
    const dialog = page.getByRole('alertdialog', { name: 'Confirm the change' });
    await expect(dialog).toHaveAccessibleDescription(/^Bypass lifts every limit until \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC\. The end time counts from when you confirm\.$/);
    expectAbout(untilIn((await dialog.getByText(/^Bypass lifts every limit/).textContent()) ?? ''), 15);

    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
    await expect(controls.getByText('Bypass is off.', { exact: true })).toBeVisible();
    await expect(emergencyBanner(page)).toHaveCount(0);
    expect((await api.json<Settings>('GET', 'settings')).bypass.on).toBe(false);
});

test('OVW-08: confirmed, bypass is on until its end time, the banner shows on every page, and no limit applies', async ({ page, rules, traffic }) => {
    const rule = await rules.create('OVW-08', { max_attempts: 1 });
    const controls = await emergencyControls(page);
    await controls.getByLabel('Bypass for').selectOption({ label: '15 minutes' });
    await controls.getByRole('button', { name: 'Turn on bypass' }).click();
    await page.getByRole('alertdialog', { name: 'Confirm the change' }).getByRole('button', { name: 'Confirm' }).click();

    const state = controls.getByText(/^Bypass is on until .+\.$/);
    await expect(state).toBeVisible();
    expectAbout(Date.parse((await state.locator('time').getAttribute('datetime')) ?? ''), 15);

    for (const { path, heading } of PAGES) {
        await page.goto(path);
        await expect(page.getByRole('heading', { level: 1, name: heading, exact: true })).toBeVisible();
        await expect(emergencyBanner(page)).toContainText('Bypass is on: no rule limits any request until');
    }

    expect(await traffic.statuses(rule.path, 3)).toEqual([200, 200, 200]);
});

test('OVW-09: "Turn off bypass" in the banner needs no confirmation, and limits apply again', async ({ page, api, rules, traffic }) => {
    const rule = await rules.create('OVW-09', { max_attempts: 1 });
    await api.json('PUT', 'settings/bypass', { state: 'on', for: '15m', confirm: true });
    expect(await traffic.statuses(rule.path, 2)).toEqual([200, 200]);

    const controls = await emergencyControls(page);
    const banner = emergencyBanner(page);
    await banner.getByRole('button', { name: 'Turn off bypass' }).click();

    // Had the server asked to confirm, a dialog would wait, and bypass would stay on.
    await expect(banner).toBeHidden();
    await expect(page.getByRole('alertdialog')).toHaveCount(0);
    await expect(controls.getByText('Bypass is off.', { exact: true })).toBeVisible();
    expect((await api.json<Settings>('GET', 'settings')).bypass.on).toBe(false);
    // Bypass counted nothing, so the rule starts afresh.
    expect(await traffic.statuses(rule.path, 2)).toEqual([200, 429]);
});

test('OVW-10: bypass with "No end time" stays on with no end time', async ({ page, api }) => {
    const controls = await emergencyControls(page);
    await controls.getByLabel('Bypass for').selectOption({ label: 'No end time' });
    await controls.getByRole('button', { name: 'Turn on bypass' }).click();
    const dialog = page.getByRole('alertdialog', { name: 'Confirm the change' });
    await expect(dialog).toHaveAccessibleDescription('Bypass lifts every limit, with no end time. The end time counts from when you confirm.');
    await dialog.getByRole('button', { name: 'Confirm' }).click();

    await expect(controls.getByText('Bypass is on, with no end time.', { exact: true })).toBeVisible();
    await expect(emergencyBanner(page)).toContainText('Bypass is on: no rule limits any request, with no end time.');
    expect((await api.json<Settings>('GET', 'settings')).bypass).toEqual({ on: true, until: null });
});

test('OVW-11: a factor of 2 applies without confirmation, until its end time, and doubles every limit', async ({ page, rules, traffic }) => {
    const rule = await rules.create('OVW-11');
    const controls = await emergencyControls(page);
    await controls.getByLabel('Factor', { exact: true }).fill('2');
    await controls.getByLabel('Scale for').selectOption({ label: '1 hour' });
    await controls.getByRole('button', { name: 'Apply' }).click();

    const state = controls.getByText(/^Every limit is multiplied by 2 until .+\.$/);
    await expect(state).toBeVisible();
    await expect(page.getByRole('alertdialog')).toHaveCount(0);
    expectAbout(Date.parse((await state.locator('time').getAttribute('datetime')) ?? ''), 60);
    await expect(emergencyBanner(page)).toContainText('Every limit is multiplied by 2 until');

    const response = await traffic.get(rule.path);
    expect(response.headers()['x-ratelimit-limit']).toBe('4');
    expect(response.headers()['x-ratelimit-remaining']).toBe('3');
});

test('OVW-12: a factor below 1 asks to confirm, since it lowers every limit', async ({ page, api }) => {
    const controls = await emergencyControls(page);
    await controls.getByLabel('Factor', { exact: true }).fill('0.5');
    await controls.getByRole('button', { name: 'Apply' }).click();

    const dialog = page.getByRole('alertdialog', { name: 'Confirm the change' });
    await expect(dialog).toHaveAccessibleDescription(
        /^Every limit is multiplied by 0\.5 until \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC, so clients are limited sooner\. The end time counts from when you confirm\.$/,
    );
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
    await expect(controls.getByText('Limits are not scaled.', { exact: true })).toBeVisible();
    expect((await api.json<Settings>('GET', 'settings')).scale.factor).toBeNull();
});

test('OVW-13: a factor out of range, with too many decimals or not a number shows a message under Factor, and changes nothing', async ({ page, api }) => {
    const controls = await emergencyControls(page);
    const factor = controls.getByLabel('Factor', { exact: true });

    for (const value of ['11', '0.05', '1.234', 'abc']) {
        await factor.fill(value);
        const answered = page.waitForResponse((response) => new URL(response.url()).pathname === '/dynamic-throttle/api/settings/scale');
        await controls.getByRole('button', { name: 'Apply' }).click();
        expect((await answered).status(), `the factor ${value}`).toBe(422);
        await expect(factor).toHaveAttribute('aria-invalid', 'true');
        await expect(factor).toHaveAccessibleDescription(`The factor must be from 0.1 to 10, with at most two decimals, such as 1.5, got "${value}".`);
    }

    await expect(page.getByRole('alertdialog')).toHaveCount(0);
    await expect(controls.getByText('Limits are not scaled.', { exact: true })).toBeVisible();
    expect((await api.json<Settings>('GET', 'settings')).scale.factor).toBeNull();
});

test('OVW-14: Apply is disabled until a factor is typed', async ({ page }) => {
    const controls = await emergencyControls(page);
    const apply = controls.getByRole('button', { name: 'Apply' });
    await expect(apply).toBeDisabled();

    await controls.getByLabel('Factor', { exact: true }).fill('   ');
    await expect(apply).toBeDisabled();

    await controls.getByLabel('Factor', { exact: true }).fill('2');
    await expect(apply).toBeEnabled();
});

test('OVW-15: a change the server cannot be reached for says so, and changes nothing', async ({ page, api }) => {
    await page.route('**/dynamic-throttle/api/settings/bypass', (route) => route.abort('internetdisconnected'));
    const controls = await emergencyControls(page);
    await controls.getByRole('button', { name: 'Turn on bypass' }).click();

    await expect(controls.getByText('The server could not be reached: check the connection, then try again.', { exact: true })).toBeVisible();
    await expect(page.getByRole('alertdialog')).toHaveCount(0);
    expect((await api.json<Settings>('GET', 'settings')).bypass.on).toBe(false);
});
