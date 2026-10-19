# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: harness.spec.ts >> an emergency setting never outlives its test >> a test that fails with bypass and scaling on
- Location: e2e/harness.spec.ts:38:5

# Error details

```
Error: expect(received).toBe(expected) // Object.is equality

Expected: "failed half-way"
Received: "the test"
```

# Test source

```ts
  1  | import { expect, test } from './fixtures';
  2  | 
  3  | // The suite's own promises: the page guard fails a test whose page breaks a rule, and no
  4  | // test leaves an emergency setting on for the next. Each test below is expected to fail:
  5  | // it passes only when the guard or the test fails it.
  6  | 
  7  | test('the page guard fails a test whose page breaks the Content Security Policy', async ({ page }) => {
  8  |     test.fail(true, 'the guard must fail this test');
  9  |     await page.goto('/dynamic-throttle');
  10 |     await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible();
  11 |     // A style element without the page's nonce, which the policy refuses.
  12 |     await page.evaluate(() => {
  13 |         const style = document.createElement('style');
  14 |         style.textContent = 'body { outline: 1px solid red; }';
  15 |         document.head.append(style);
  16 |     });
  17 |     await page.waitForTimeout(200);
  18 | });
  19 | 
  20 | test('the page guard fails a test whose page logs an error', async ({ page }) => {
  21 |     test.fail(true, 'the guard must fail this test');
  22 |     await page.goto('/dynamic-throttle');
  23 |     await page.evaluate(() => console.error('Something broke.'));
  24 | });
  25 | 
  26 | test('the page guard fails a test whose page throws', async ({ page }) => {
  27 |     test.fail(true, 'the guard must fail this test');
  28 |     await page.goto('/dynamic-throttle');
  29 |     await page.evaluate(() => {
  30 |         setTimeout(() => {
  31 |             throw new Error('Uncaught on purpose.');
  32 |         });
  33 |     });
  34 |     await page.waitForTimeout(200);
  35 | });
  36 | 
  37 | test.describe.serial('an emergency setting never outlives its test', () => {
  38 |     test('a test that fails with bypass and scaling on', async ({ api }) => {
  39 |         test.fail(true, 'this test fails on purpose, with both settings on');
  40 |         await api.json('PUT', 'settings/bypass', { state: 'on', for: '1h', confirm: true });
  41 |         await api.json('PUT', 'settings/scale', { factor: '0.5', for: '1h', confirm: true });
> 42 |         expect('the test').toBe('failed half-way');
     |                            ^ Error: expect(received).toBe(expected) // Object.is equality
  43 |     });
  44 | 
  45 |     test('the next test finds both off', async ({ api }) => {
  46 |         const settings = await api.json<{ bypass: { on: boolean }; scale: { factor: string | null } }>('GET', 'settings');
  47 |         expect(settings.bypass.on).toBe(false);
  48 |         expect(settings.scale.factor).toBeNull();
  49 |     });
  50 | });
  51 | 
```