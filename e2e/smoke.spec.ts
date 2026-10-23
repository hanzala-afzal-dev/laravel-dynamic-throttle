import { expect, test } from './fixtures';

// The smoke flow ADR-0028 planned: a rule made in the dashboard limits its route.

test('LIM-01: a rule made in the dashboard limits its route, with the rate-limit headers', async ({ page, rules, traffic }) => {
    const slug = `lim-01-${Math.random().toString(36).slice(2, 8)}`;

    await page.goto('/dynamic-throttle/rules/new');
    await page.getByLabel('Name', { exact: true }).fill(`LIM-01 ${slug}`);
    await page.getByLabel('Method', { exact: true }).selectOption('GET');
    await page.getByLabel('Pattern', { exact: true }).fill(`e2e/${slug}`);
    await page.getByLabel('Attempts', { exact: true }).fill('2');
    await page.getByLabel('Mode', { exact: true }).selectOption('enforce');
    await page.getByRole('button', { name: 'Create rule' }).click();

    await expect(page).toHaveURL(/\/dynamic-throttle\/rules\/\d+$/);
    rules.track(Number(new URL(page.url()).pathname.split('/').pop()));
    await expect(page.getByRole('heading', { level: 1, name: `LIM-01 ${slug}` })).toBeVisible();

    const first = await traffic.get(`/e2e/${slug}`);
    expect(first.status()).toBe(200);
    expect(first.headers()['x-ratelimit-limit']).toBe('2');
    expect(first.headers()['x-ratelimit-remaining']).toBe('1');

    const second = await traffic.get(`/e2e/${slug}`);
    expect(second.status()).toBe(200);
    expect(second.headers()['x-ratelimit-remaining']).toBe('0');

    const third = await traffic.get(`/e2e/${slug}`);
    expect(third.status()).toBe(429);
    const headers = third.headers();
    expect(headers['x-ratelimit-limit']).toBe('2');
    expect(headers['x-ratelimit-remaining']).toBe('0');
    // The sliding window carries this window's count into the next, so the wait runs up to two windows (SPEC-001).
    const retryAfter = Number(headers['retry-after']);
    expect(retryAfter).toBeGreaterThanOrEqual(1);
    expect(retryAfter).toBeLessThanOrEqual(120);
    // The reset is now plus Retry-After, in Unix seconds, as Laravel sends it.
    expect(Math.abs(Number(headers['x-ratelimit-reset']) - (Date.now() / 1000 + retryAfter))).toBeLessThanOrEqual(3);
    // Laravel's own 429. The Workbench app runs in debug, so the body also holds the trace.
    expect((await third.json()).message).toBe('Too Many Attempts.');
});

test('LIM-08: a change saved in the dashboard applies from the next request, and keeps the count', async ({ page, rules, traffic }) => {
    const rule = await rules.create('LIM-08');
    expect((await traffic.get(rule.path)).headers()['x-ratelimit-limit']).toBe('2');

    await page.goto(`/dynamic-throttle/rules/${rule.id}/edit`);
    await page.getByLabel('Attempts', { exact: true }).fill('5');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page).toHaveURL(new RegExp(`/dynamic-throttle/rules/${rule.id}$`));

    const next = await traffic.get(rule.path);
    expect(next.headers()['x-ratelimit-limit']).toBe('5');
    // The request before the change still counts: 5 - 2.
    expect(next.headers()['x-ratelimit-remaining']).toBe('3');
});
