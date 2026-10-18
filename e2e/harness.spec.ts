import { expect, test } from './fixtures';

// The suite's own promises (SPEC-032): the page guard fails a test whose page breaks a rule
// (AC-2), and no test leaves an emergency setting on for the next (AC-5). Each test below
// is expected to fail: it passes only when the guard or the test fails it.

test('AC-2: the page guard fails a test whose page breaks the Content Security Policy', async ({ page }) => {
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

test('AC-2: the page guard fails a test whose page logs an error', async ({ page }) => {
    test.fail(true, 'the guard must fail this test');
    await page.goto('/dynamic-throttle');
    await page.evaluate(() => console.error('Something broke.'));
});

test('AC-2: the page guard fails a test whose page throws', async ({ page }) => {
    test.fail(true, 'the guard must fail this test');
    await page.goto('/dynamic-throttle');
    await page.evaluate(() => {
        setTimeout(() => {
            throw new Error('Uncaught on purpose.');
        });
    });
    await page.waitForTimeout(200);
});

test.describe.serial('AC-5: an emergency setting never outlives its test', () => {
    test('AC-5: a test that fails with bypass and scaling on', async ({ api }) => {
        test.fail(true, 'this test fails on purpose, with both settings on');
        await api.json('PUT', 'settings/bypass', { state: 'on', for: '1h', confirm: true });
        await api.json('PUT', 'settings/scale', { factor: '0.5', for: '1h', confirm: true });
        expect('the test').toBe('failed half-way');
    });

    test('AC-5: the next test finds both off', async ({ api }) => {
        const settings = await api.json<{ bypass: { on: boolean }; scale: { factor: string | null } }>('GET', 'settings');
        expect(settings.bypass.on).toBe(false);
        expect(settings.scale.factor).toBeNull();
    });
});
