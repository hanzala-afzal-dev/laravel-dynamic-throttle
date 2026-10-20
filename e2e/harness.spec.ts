import { expect, test } from './fixtures';
import { requireParallelServer } from './support/environment';

// The suite's own promises: the page guard fails a test whose page breaks a rule, and no
// test leaves an emergency setting on for the next. Each test below is expected to fail:
// it passes only when the guard or the test fails it.

test('the page guard fails a test whose page breaks the Content Security Policy', async ({ page }) => {
    test.fail(true, 'the guard must fail this test');
    await page.goto('/dynamic-throttle');
    await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible();
    // A style element without the page's nonce, which the policy refuses.
    await page.evaluate(() => {
        const style = document.createElement('style');
        style.textContent = 'body { outline: 1px solid red; }';
        document.head.append(style);
    });
    await page.waitForTimeout(200);
});

test('the page guard fails a test whose page logs an error', async ({ page }) => {
    test.fail(true, 'the guard must fail this test');
    await page.goto('/dynamic-throttle');
    await page.evaluate(() => console.error('Something broke.'));
});

test('the page guard fails a test whose page throws', async ({ page }) => {
    test.fail(true, 'the guard must fail this test');
    await page.goto('/dynamic-throttle');
    await page.evaluate(() => {
        setTimeout(() => {
            throw new Error('Uncaught on purpose.');
        });
    });
    await page.waitForTimeout(200);
});

test.describe.serial('an emergency setting never outlives its test', () => {
    test('a test that fails with bypass and scaling on', async ({ api }) => {
        test.fail(true, 'this test fails on purpose, with both settings on');
        await api.json('PUT', 'settings/bypass', { state: 'on', for: '1h', confirm: true });
        await api.json('PUT', 'settings/scale', { factor: '0.5', for: '1h', confirm: true });
        expect('the test').toBe('failed half-way');
    });

    test('the next test finds both off', async ({ api }) => {
        const settings = await api.json<{ bypass: { on: boolean }; scale: { factor: string | null } }>('GET', 'settings');
        expect(settings.bypass.on).toBe(false);
        expect(settings.scale.factor).toBeNull();
    });
});

// Issues #112 to #132: on native Windows, PHP's built-in server cannot fork workers, so it
// answers one request at a time, and the tests time out one by one. The run stops first.
test('the suite refuses PHP\'s built-in server on native Windows, unless told to try anyway', () => {
    expect(() => requireParallelServer('win32', {})).toThrow('PHP\'s built-in server answers one request at a time on Windows');
    expect(() => requireParallelServer('win32', { E2E_ALLOW_WINDOWS: '1' })).not.toThrow();
    expect(() => requireParallelServer('linux', {})).not.toThrow();
    expect(() => requireParallelServer('darwin', {})).not.toThrow();
});
