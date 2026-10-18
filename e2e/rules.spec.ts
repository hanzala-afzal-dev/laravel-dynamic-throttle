import type { Locator, Page } from '@playwright/test';
import { card, expect, type Rule, test } from './fixtures';

// The Rules pages (SPEC-022): the list with its search and filters, the form with its
// templates, a rule's own page, and the changes that need confirming.

/** A share as the dashboard writes it in en-US, such as 66.7%. */
const share = (part: number, whole: number) => new Intl.NumberFormat('en-US', { style: 'percent', maximumFractionDigits: 1 }).format(whole === 0 ? 0 : part / whole);

/** A slug of its own for a rule a test makes in the form. */
const slugFor = (caseId: string) => `${caseId.toLowerCase()}-${Math.random().toString(36).slice(2, 8)}`;

/** The list's row of a rule, found by its name's link. */
function rowOf(page: Page, name: string): Locator {
    return page.getByRole('row').filter({ has: page.getByRole('link', { name, exact: true }) });
}

/** The names the list shows, top to bottom. */
function listedNames(page: Page): Promise<string[]> {
    return page.locator('tbody tr td:first-child a').allTextContents();
}

/** A rule page's Settings panel, as label → value. */
async function settingsOf(page: Page): Promise<Record<string, string>> {
    const panel = page.getByRole('region', { name: 'Settings' });
    await expect(panel).toBeVisible();

    return panel.locator('dl').evaluate((list) => Object.fromEntries([...list.querySelectorAll('dt')].map((term) => [term.textContent?.trim() ?? '', term.nextElementSibling?.textContent?.trim() ?? ''])));
}

/** The rule whose page is open, by the id in the URL. */
function openRuleId(page: Page): number {
    return Number(new URL(page.url()).pathname.split('/').pop());
}

/** The form's fields, by their labels. */
function form(page: Page) {
    return {
        name: page.getByLabel('Name', { exact: true }),
        method: page.getByLabel('Method', { exact: true }),
        pattern: page.getByLabel('Pattern', { exact: true }),
        per: page.getByLabel('Per', { exact: true }),
        attempts: page.getByLabel('Attempts', { exact: true }),
        window: page.getByLabel('Window', { exact: true }),
        unit: page.getByLabel('Window unit', { exact: true }),
        mode: page.getByLabel('Mode', { exact: true }),
        priority: page.getByLabel('Priority', { exact: true }),
        body: page.getByLabel('429 body', { exact: true }),
    };
}

test('RUL-01: the list shows each rule\'s settings, in the order they are evaluated: by priority, then by id', async ({ page, rules, traffic }) => {
    const later = await rules.create('RUL-01', { priority: 7 });
    const first = await rules.create('RUL-01', { priority: 6 });
    const second = await rules.create('RUL-01', { priority: 6, method: 'POST', max_attempts: 5, window_seconds: 90, mode: 'dry_run', enabled: false });
    expect(await traffic.statuses(first.path, 3)).toEqual([200, 200, 429]);

    await page.goto('/dynamic-throttle/rules');
    await expect(page.getByRole('columnheader')).toHaveText(['Name', 'Applies to', 'Per', 'Limit', 'Mode', 'Status', 'Last hour', 'Actions']);
    await expect(rowOf(page, later.name)).toBeVisible();
    expect((await listedNames(page)).filter((name) => name.startsWith('RUL-01 '))).toEqual([first.name, second.name, later.name]);

    await expect(rowOf(page, first.name).getByRole('cell')).toHaveText([first.name, `Any e2e/${first.slug}`, 'IP', '2 per minute', 'Enforcing', 'Enabled', '1 block', '']);
    await expect(rowOf(page, second.name).getByRole('cell')).toHaveText([second.name, `POST e2e/${second.slug}`, 'IP', '5 per 90 seconds', 'Dry-run', 'Disabled', '—', '']);
    await expect(page.getByRole('link', { name: first.name, exact: true })).toHaveAttribute('href', `/dynamic-throttle/rules/${first.id}`);
});

test('RUL-02: the search waits for a pause, goes to the URL, and finds a name or a pattern, ignoring case and spaces', async ({ page, rules }) => {
    const wanted = await rules.create('RUL-02');
    const other = await rules.create('RUL-02');
    await page.goto('/dynamic-throttle/rules');
    const search = page.getByRole('searchbox', { name: 'Search rules' });

    await search.fill(`  ${wanted.slug.toUpperCase()}  `);
    await expect(page).toHaveURL(new RegExp(`/dynamic-throttle/rules\\?search=${wanted.slug.toUpperCase()}$`));
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(rowOf(page, wanted.name)).toBeVisible();

    // The names hold the slug, and only the pattern holds e2e/.
    await search.fill(`e2e/${other.slug}`);
    await expect(page).toHaveURL(new RegExp(`\\?search=e2e%2F${other.slug}$`));
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(rowOf(page, other.name)).toBeVisible();
});

test('RUL-03: a search that matches nothing says so', async ({ page }) => {
    await page.goto('/dynamic-throttle/rules');
    await page.getByRole('searchbox', { name: 'Search rules' }).fill('no rule has this name');
    await expect(page.getByText('No rule matches the search and filters.', { exact: true })).toBeVisible();
    await expect(page.locator('tbody tr')).toHaveCount(0);
});

test('RUL-04: the Status and Mode filters narrow the list, go to the URL, and stay after a reload', async ({ page, rules }) => {
    const rule = await rules.create('RUL-04', { mode: 'dry_run', enabled: false });
    const enforcing = await rules.create('RUL-04');
    await page.goto('/dynamic-throttle/rules');
    await expect(rowOf(page, enforcing.name)).toBeVisible();
    const status = page.getByLabel('Status', { exact: true });
    const mode = page.getByLabel('Mode', { exact: true });

    await status.selectOption({ label: 'Disabled' });
    await mode.selectOption({ label: 'Dry-run' });
    await expect(page).toHaveURL(/\/dynamic-throttle\/rules\?enabled=false&mode=dry_run$/);

    for (const load of ['chosen', 'reloaded']) {
        await expect(status, load).toHaveValue('false');
        await expect(mode, load).toHaveValue('dry_run');
        await expect(rowOf(page, rule.name), load).toBeVisible();
        await expect(rowOf(page, enforcing.name), load).toHaveCount(0);
        // Every row is a disabled dry-run rule, in the columns Mode and Status.
        expect(new Set(await page.locator('tbody tr td:nth-child(5)').allTextContents()), load).toEqual(new Set(['Dry-run']));
        expect(new Set(await page.locator('tbody tr td:nth-child(6)').allTextContents()), load).toEqual(new Set(['Disabled']));
        await page.reload();
    }
});

test('RUL-05: the sidebar\'s Rules link clears the search, and every rule shows again', async ({ page, api, rules }) => {
    const rule = await rules.create('RUL-05');
    await page.goto(`/dynamic-throttle/rules?search=${rule.slug}`);
    const search = page.getByRole('searchbox', { name: 'Search rules' });
    await expect(search).toHaveValue(rule.slug);
    await expect(page.locator('tbody tr')).toHaveCount(1);

    await page.getByRole('navigation', { name: 'Pages' }).getByRole('link', { name: 'Rules', exact: true }).click();
    await expect(page).toHaveURL(/\/dynamic-throttle\/rules$/);
    await expect(search).toHaveValue('');
    const { total } = await api.json<{ total: number }>('GET', 'rules');
    await expect(page.locator('tbody tr')).toHaveCount(Math.min(total, 100));
});

test('RUL-06: a new rule starts in dry-run, and the form says why', async ({ page }) => {
    await page.goto('/dynamic-throttle/rules');
    await page.getByRole('link', { name: 'New rule' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'New rule' })).toBeVisible();
    await expect(page.getByText('A new rule starts in dry-run: it counts what it would block, and blocks nothing until you promote it.', { exact: true })).toBeVisible();
    await expect(form(page).mode).toHaveValue('dry_run');
});

test('RUL-07: the Login template fills the form in', async ({ page }) => {
    await page.goto('/dynamic-throttle/rules/new');
    await page.getByLabel('Template').selectOption('Login');
    const fields = form(page);

    await expect(fields.name).toHaveValue('Login');
    await expect(fields.method).toHaveValue('POST');
    await expect(fields.pattern).toHaveValue('login');
    await expect(fields.per).toHaveValue('ip');
    await expect(fields.attempts).toHaveValue('5');
    await expect(fields.window).toHaveValue('1');
    await expect(fields.unit).toHaveValue('minutes');
    await expect(fields.mode).toHaveValue('dry_run');
    await expect(fields.priority).toHaveValue('10');
    await expect(fields.body).toHaveValue('{"message":"Too many login attempts. Try again in a minute."}');
});

test('RUL-08: "Create rule" saves the rule and opens its page, and the list shows it', async ({ page, rules }) => {
    const slug = slugFor('RUL-08');
    const fields = form(page);
    await page.goto('/dynamic-throttle/rules/new');
    await fields.name.fill(`RUL-08 ${slug}`);
    await page.getByLabel('Description').fill('Made by the browser tests.');
    await fields.method.selectOption('GET');
    await fields.pattern.fill(`e2e/${slug}`);
    await fields.per.selectOption({ label: 'Header' });
    await page.getByLabel('Header name').fill('X-Tenant');
    await fields.attempts.fill('3');
    await fields.window.fill('5');
    await fields.unit.selectOption('minutes');
    await fields.priority.fill('15');
    await page.getByRole('button', { name: 'Create rule' }).click();

    await expect(page).toHaveURL(/\/dynamic-throttle\/rules\/\d+$/);
    rules.track(openRuleId(page));
    await expect(page.getByRole('heading', { level: 1, name: `RUL-08 ${slug}` })).toBeVisible();
    await expect(page.getByText('Made by the browser tests.', { exact: true })).toBeVisible();
    expect(await settingsOf(page)).toMatchObject({
        'Applies to': `GET e2e/${slug}`,
        Per: 'Header X-Tenant',
        Limit: '3 per 5 minutes',
        Priority: '15',
        'When the counters fail': 'Let requests through',
        '429 body': 'The default',
    });

    await page.getByRole('navigation', { name: 'Pages' }).getByRole('link', { name: 'Rules', exact: true }).click();
    await expect(rowOf(page, `RUL-08 ${slug}`)).toBeVisible();
});

test('RUL-09: invalid input is refused, with its message under its field, which is marked invalid, and nothing is saved', async ({ page, api }) => {
    const slug = slugFor('RUL-09');
    const fields = form(page);
    await page.goto('/dynamic-throttle/rules/new');
    await fields.name.fill(`RUL-09 ${slug}`);
    await fields.pattern.fill(`e2e/${slug}`);
    await fields.attempts.fill('2');

    /** Sends the form, and expects the API to refuse it with a message under the field. */
    const refused = async (field: Locator, message: RegExp) => {
        const answered = page.waitForResponse((response) => new URL(response.url()).pathname === '/dynamic-throttle/api/rules' && response.request().method() === 'POST');
        await page.getByRole('button', { name: 'Create rule' }).click();
        expect((await answered).status()).toBe(422);
        await expect(field).toHaveAttribute('aria-invalid', 'true');
        await expect(field).toHaveAccessibleDescription(message);
    };

    await fields.name.fill('x'.repeat(101));
    await refused(fields.name, /100 characters/);
    await fields.name.fill(`RUL-09 ${slug}`);

    await fields.pattern.fill('api/');
    await refused(fields.pattern, /^The pattern must not end with a slash/);
    await fields.pattern.fill('e2e/a b');
    await refused(fields.pattern, /pattern.*invalid/i);
    await fields.pattern.fill(`e2e/${slug}`);

    await fields.attempts.fill('0');
    await refused(fields.attempts, /between 1 and/);
    await fields.attempts.fill('2');

    await fields.window.fill('2');
    await fields.unit.selectOption('days');
    await refused(fields.window, /between 1 and 86400/);
    await fields.unit.selectOption('minutes');

    await fields.per.selectOption({ label: 'Header' });
    await refused(page.getByLabel('Header name'), /required/);

    await expect(page).toHaveURL(/\/dynamic-throttle\/rules\/new$/);
    expect((await api.json<{ total: number }>('GET', `rules?search=${slug}`)).total).toBe(0);
});

test('RUL-10: a 429 body that is not a JSON object is refused before anything is sent', async ({ page }) => {
    const slug = slugFor('RUL-10');
    const fields = form(page);
    const sent: string[] = [];
    page.on('request', (request) => {
        if (request.method() === 'POST' && new URL(request.url()).pathname === '/dynamic-throttle/api/rules') {
            sent.push(request.postData() ?? '');
        }
    });
    await page.goto('/dynamic-throttle/rules/new');
    await fields.name.fill(`RUL-10 ${slug}`);
    await fields.pattern.fill(`e2e/${slug}`);

    for (const body of ['not json', '[1, 2]']) {
        await fields.body.fill(body);
        await page.getByRole('button', { name: 'Create rule' }).click();
        await expect(fields.body).toHaveAttribute('aria-invalid', 'true');
        await expect(fields.body).toHaveAccessibleDescription('The 429 body must be a JSON object, such as {"message": "Too many requests."}.');
    }

    await expect(page).toHaveURL(/\/dynamic-throttle\/rules\/new$/);
    expect(sent).toEqual([]);
});

test('RUL-11: a window of 1.5 minutes is saved as 90 seconds', async ({ page, api, rules }) => {
    const slug = slugFor('RUL-11');
    const fields = form(page);
    await page.goto('/dynamic-throttle/rules/new');
    await fields.name.fill(`RUL-11 ${slug}`);
    await fields.pattern.fill(`e2e/${slug}`);
    await fields.attempts.fill('3');
    await fields.window.fill('1.5');
    await fields.unit.selectOption('minutes');
    await page.getByRole('button', { name: 'Create rule' }).click();

    await expect(page).toHaveURL(/\/dynamic-throttle\/rules\/\d+$/);
    const id = openRuleId(page);
    rules.track(id);
    expect((await settingsOf(page)).Limit).toBe('3 per 90 seconds');
    expect((await api.json<{ rule: Rule }>('GET', `rules/${id}`)).rule.window_seconds).toBe(90);
});

test('RUL-12: saving an edit that changes nothing says so, and sends nothing', async ({ page, rules }) => {
    const rule = await rules.create('RUL-12');
    const sent: string[] = [];
    page.on('request', (request) => {
        if (request.method() === 'PATCH') {
            sent.push(request.url());
        }
    });
    await page.goto(`/dynamic-throttle/rules/${rule.id}/edit`);
    await expect(page.getByRole('heading', { level: 1, name: `Edit ${rule.name}` })).toBeVisible();
    await page.getByRole('button', { name: 'Save changes' }).click();

    await expect(page.getByText('Nothing changed.', { exact: true })).toBeVisible();
    await expect(page).toHaveURL(new RegExp(`/dynamic-throttle/rules/${rule.id}/edit$`));
    expect(sent).toEqual([]);
});

test('RUL-13: an edit sends only the field it changed, and the rule\'s history shows the change', async ({ page, rules }) => {
    const rule = await rules.create('RUL-13');
    await page.goto(`/dynamic-throttle/rules/${rule.id}/edit`);
    await form(page).attempts.fill('7');
    const sent = page.waitForRequest((request) => request.method() === 'PATCH' && new URL(request.url()).pathname === `/dynamic-throttle/api/rules/${rule.id}`);
    await page.getByRole('button', { name: 'Save changes' }).click();

    expect((await sent).postDataJSON()).toEqual({ max_attempts: 7 });
    await expect(page).toHaveURL(new RegExp(`/dynamic-throttle/rules/${rule.id}$`));
    expect((await settingsOf(page)).Limit).toBe('7 per minute');
    const change = page.getByRole('region', { name: 'History' }).getByRole('listitem').filter({ hasText: 'Changed' });
    await expect(change).toHaveCount(1);
    await expect(change).toContainText('max_attempts: 2 → 7');
});

test('RUL-14: Duplicate opens the form as a copy, in dry-run, which saves as a new rule', async ({ page, rules }) => {
    const rule = await rules.create('RUL-14');
    await page.goto(`/dynamic-throttle/rules/${rule.id}`);
    await page.getByRole('link', { name: 'Duplicate' }).click();

    await expect(page).toHaveURL(new RegExp(`/dynamic-throttle/rules/new\\?from=${rule.id}$`));
    await expect(page.getByRole('heading', { level: 1, name: 'New rule' })).toBeVisible();
    await expect(page.getByText(`A copy of ${rule.name}, in dry-run until you promote it.`, { exact: true })).toBeVisible();
    const fields = form(page);
    await expect(fields.name).toHaveValue(`Copy of ${rule.name}`);
    await expect(fields.mode).toHaveValue('dry_run');
    await expect(fields.pattern).toHaveValue(rule.pattern);
    await expect(fields.attempts).toHaveValue('2');

    await page.getByRole('button', { name: 'Create rule' }).click();
    await expect(page).toHaveURL(/\/dynamic-throttle\/rules\/\d+$/);
    rules.track(openRuleId(page));
    expect(openRuleId(page)).not.toBe(rule.id);
    await expect(page.getByRole('heading', { level: 1, name: `Copy of ${rule.name}` })).toBeVisible();
    await expect(page.getByText('Dry-run', { exact: true })).toBeVisible();
});

test('RUL-15: the list\'s switch turns a dry-run rule off with no dialog', async ({ page, api, rules }) => {
    const rule = await rules.create('RUL-15', { mode: 'dry_run' });
    await page.goto('/dynamic-throttle/rules');
    const toggle = page.getByRole('switch', { name: `${rule.name} enabled` });
    await expect(toggle).toBeChecked();

    await toggle.click();
    // Had the server asked to confirm, a dialog would wait, and the rule would stay on.
    await expect(toggle).not.toBeChecked();
    await expect(page.getByRole('alertdialog')).toHaveCount(0);
    await expect(rowOf(page, rule.name).getByRole('cell').nth(5)).toHaveText('Disabled');
    expect((await api.json<{ rule: Rule }>('GET', `rules/${rule.id}`)).rule.enabled).toBe(false);
});

test('RUL-16: the switch asks to confirm before an enforcing rule stops limiting; Cancel leaves it on, Confirm turns it off', async ({ page, api, rules, traffic }) => {
    const rule = await rules.create('RUL-16', { max_attempts: 1 });
    expect(await traffic.statuses(rule.path, 2)).toEqual([200, 429]);
    await page.goto('/dynamic-throttle/rules');
    const toggle = page.getByRole('switch', { name: `${rule.name} enabled` });
    const dialog = page.getByRole('alertdialog', { name: 'Confirm the change' });

    await toggle.click();
    await expect(dialog).toHaveAccessibleDescription(`Rule ${rule.id} (${rule.name}) stops limiting requests until it is enabled again.`);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
    await expect(toggle).toBeChecked();
    expect((await api.json<{ rule: Rule }>('GET', `rules/${rule.id}`)).rule.enabled).toBe(true);

    await toggle.click();
    await dialog.getByRole('button', { name: 'Confirm' }).click();
    await expect(toggle).not.toBeChecked();
    expect((await api.json<{ rule: Rule }>('GET', `rules/${rule.id}`)).rule.enabled).toBe(false);
    expect(await traffic.statuses(rule.path, 3)).toEqual([200, 200, 200]);
});

test('RUL-17: Promote shows a dry-run rule\'s last 24 hours, and Enforce makes it answer 429', async ({ page, rules, traffic }) => {
    const rule = await rules.create('RUL-17', { mode: 'dry_run', max_attempts: 1 });
    await page.goto(`/dynamic-throttle/rules/${rule.id}`);
    await page.getByRole('button', { name: 'Promote' }).click();

    const dialog = page.getByRole('alertdialog', { name: `Promote ${rule.name} to enforcing` });
    // The day's numbers come from the hourly table, which holds none for a new rule.
    await expect(dialog).toContainText('In the last 24 hours it checked no request, so there is no evidence of what it would block.');
    await expect(dialog).toContainText('Once enforcing, it answers 429 to those requests.');
    await dialog.getByRole('button', { name: 'Enforce' }).click();

    await expect(dialog).toBeHidden();
    await expect(page.getByText('Enforcing', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Promote' })).toHaveCount(0);
    expect(await traffic.statuses(rule.path, 2)).toEqual([200, 429]);
});

test('RUL-18: Delete names the rule and says it stops limiting, then returns to the list without it', async ({ page, api, rules, traffic }) => {
    const rule = await rules.create('RUL-18', { max_attempts: 1 });
    expect(await traffic.statuses(rule.path, 2)).toEqual([200, 429]);
    await page.goto(`/dynamic-throttle/rules/${rule.id}`);
    await page.getByRole('button', { name: 'Delete' }).click();

    const dialog = page.getByRole('alertdialog', { name: `Delete ${rule.name}?` });
    await expect(dialog).toHaveAccessibleDescription('Its exemptions are deleted with it. It stops limiting requests. This cannot be undone: the audit log keeps its settings.');
    await dialog.getByRole('button', { name: 'Delete' }).click();

    await expect(page).toHaveURL(/\/dynamic-throttle\/rules$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Rules' })).toBeVisible();
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await expect(rowOf(page, rule.name)).toHaveCount(0);
    expect((await api.send('GET', `rules/${rule.id}`)).status()).toBe(404);
    expect(await traffic.statuses(rule.path, 3)).toEqual([200, 200, 200]);
});

test('RUL-19: a rule\'s page shows its settings, traffic, offenders, exemptions and history', async ({ page, rules, traffic }) => {
    const rule = await rules.create('RUL-19', { max_attempts: 1 });
    expect(await traffic.statuses(rule.path, 3)).toEqual([200, 429, 429]);
    await page.goto(`/dynamic-throttle/rules/${rule.id}`);

    await expect(page.getByRole('heading', { level: 1, name: rule.name })).toBeVisible();
    expect(await settingsOf(page)).toMatchObject({
        'Applies to': `Any e2e/${rule.slug}`,
        Per: 'IP',
        Limit: '1 per minute',
        Priority: String(rule.priority),
        'When the counters fail': 'Let requests through',
        '429 body': 'The default',
    });

    await expect(card(page, 'Traffic').getByText(`3 checks, 2 blocks (${share(2, 3)}) and 0 would-blocks in the last hour.`, { exact: true })).toBeVisible();

    // The Workbench app counts on Redis, which keeps the offenders, by identity hash.
    const offenders = page.getByRole('region', { name: 'Offenders' });
    await expect(offenders.getByText(/^1 different clients? blocked$/)).toBeVisible();
    await expect(offenders.getByRole('row').nth(1).getByRole('cell')).toHaveText([/^\S{12}…$/, '2']);

    const exemptions = page.getByRole('region', { name: 'Exemptions' });
    await expect(exemptions.getByText('No exemption names this rule.', { exact: true })).toBeVisible();
    await expect(exemptions.getByRole('link', { name: 'See its exemptions' })).toHaveAttribute('href', `/dynamic-throttle/exemptions?rule=${rule.id}`);

    // The rule was made through the API, as a guest, who manages in the Workbench app.
    const history = page.getByRole('region', { name: 'History' }).getByRole('listitem');
    await expect(history).toHaveCount(1);
    await expect(history.locator('[data-action]')).toHaveText('Created');
    await expect(history.locator('code')).toHaveText('guest');
});

test('RUL-20: a rule that does not exist says so, with a link back to the list', async ({ page }) => {
    await page.goto('/dynamic-throttle/rules/999999');
    await expect(page.getByRole('heading', { level: 1, name: 'There is no rule 999999.' })).toBeVisible();
    await page.getByRole('link', { name: 'Back to the rules' }).click();
    await expect(page).toHaveURL(/\/dynamic-throttle\/rules$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Rules' })).toBeVisible();
});

test('RUL-21: a rule path that is not an id answers 404, as any path the dashboard does not have', async ({ page }) => {
    const response = await page.goto('/dynamic-throttle/rules/abc/edit');
    expect(response!.status()).toBe(404);
    await expect(page.locator('#dynamic-throttle')).toHaveCount(0);
});
