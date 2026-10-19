# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: harness.spec.ts >> the page guard fails a test whose page breaks the Content Security Policy
- Location: e2e/harness.spec.ts:7:1

# Error details

```
Error: the page logged no error, threw nothing, and broke no CSP rule

expect(received).toEqual(expected) // deep equality

- Expected  - 1
+ Received  + 5

- Array []
+ Array [
+   "console error: Refused to apply inline style because it violates the following Content Security Policy directive: \"style-src 'self' 'nonce-gMWrAb6V9jf/ROCmHtaX8g=='\". Either the 'unsafe-inline' keyword, a hash ('sha256-ZIcltujkUfErtlvz2/dMxE8GugZAMBL4hovCdqXCTRc='), or a nonce ('nonce-...') is required to enable inline execution.
+ ",
+   "console error: CSP violation: style-src-elem blocked inline",
+ ]
```

# Page snapshot

```yaml
- generic [ref=e3]:
  - complementary [ref=e4]:
    - generic [ref=e5]: Dynamic Throttle
    - navigation "Pages" [ref=e9]:
      - link "Overview" [ref=e10] [cursor=pointer]:
        - /url: /dynamic-throttle/
      - link "Rules" [ref=e16] [cursor=pointer]:
        - /url: /dynamic-throttle/rules
      - link "Exemptions" [ref=e20] [cursor=pointer]:
        - /url: /dynamic-throttle/exemptions
      - link "Activity" [ref=e24] [cursor=pointer]:
        - /url: /dynamic-throttle/activity
      - link "Explain" [ref=e27] [cursor=pointer]:
        - /url: /dynamic-throttle/explain
      - link "Audit log" [ref=e33] [cursor=pointer]:
        - /url: /dynamic-throttle/audit
      - link "Settings" [ref=e37] [cursor=pointer]:
        - /url: /dynamic-throttle/settings
  - generic [ref=e41]:
    - banner [ref=e42]:
      - generic [ref=e43]: local
      - generic [ref=e44]: Manager
      - 'button "Theme: system" [ref=e45]'
    - main [ref=e46]:
      - generic [ref=e47]:
        - heading "Overview" [level=1] [ref=e48]
        - generic [ref=e49]:
          - generic [ref=e50]:
            - generic [ref=e51]:
              - heading "Health" [level=2] [ref=e53]
              - button "Check again" [ref=e54]
            - generic [ref=e55]:
              - paragraph [ref=e56]: Warnings
              - list [ref=e59]:
                - listitem [ref=e60]:
                  - 'generic "The metrics have not been flushed in the last 2 hours, which is as long as the counter store keeps them: check that the scheduler runs php artisan schedule:run every minute, which runs php artisan dynamic-throttle:flush-metrics." [ref=e62]':
                    - generic [ref=e63]: "Warning:"
                    - text: "The metrics have not been flushed in the last 2 hours, which is as long as the counter store keeps them: check that the scheduler runs php artisan schedule:run every minute, which runs php artisan dynamic-throttle:flush-metrics."
            - link "See every check" [ref=e65] [cursor=pointer]:
              - /url: /dynamic-throttle/settings
          - generic [ref=e66]:
            - heading "Rules" [level=2] [ref=e69]
            - generic [ref=e70]:
              - paragraph [ref=e71]:
                - generic [ref=e72]: "3"
                - generic [ref=e73]: enabled
              - paragraph [ref=e74]: 2 enforcing, 1 dry-run
              - paragraph [ref=e75]: 1 disabled
            - link "See the rules" [ref=e77] [cursor=pointer]:
              - /url: /dynamic-throttle/rules
          - generic [ref=e78]:
            - heading "Blocks in the last hour" [level=2] [ref=e81]
            - generic [ref=e82]:
              - paragraph [ref=e83]: "0"
              - paragraph [ref=e84]: No rule checked a request.
          - generic [ref=e85]:
            - heading "Would-blocks in the last hour" [level=2] [ref=e88]
            - generic [ref=e89]:
              - paragraph [ref=e90]: "0"
              - paragraph [ref=e91]: What the dry-run rules would have blocked
        - generic [ref=e92]:
          - generic [ref=e94]:
            - generic [ref=e95]:
              - heading "Traffic" [level=2] [ref=e97]
              - tablist "Period" [ref=e98]:
                - tab "Hour" [selected] [ref=e99]
                - tab "Day" [ref=e100]
                - tab "Week" [ref=e101]
            - tabpanel "Hour" [ref=e103]:
              - paragraph [ref=e104]: No rule checked a request in the last hour.
          - generic [ref=e105]:
            - generic [ref=e106]:
              - heading "Emergency controls" [level=2] [ref=e108]
              - generic [ref=e109]: For every rule at once. Each change goes to the audit log.
            - generic [ref=e110]:
              - generic [ref=e111]:
                - heading "Bypass" [level=3] [ref=e112]
                - paragraph [ref=e113]: Bypass is off.
                - generic [ref=e114]:
                  - generic [ref=e115]:
                    - generic [ref=e116]: Bypass for
                    - combobox "Bypass for" [ref=e118]:
                      - option "15 minutes"
                      - option "1 hour" [selected]
                      - option "4 hours"
                      - option "1 day"
                      - option "No end time"
                  - button "Turn on bypass" [ref=e119]
              - generic [ref=e120]:
                - heading "Scaling" [level=3] [ref=e121]
                - paragraph [ref=e122]: Limits are not scaled.
                - generic [ref=e124]:
                  - generic [ref=e125]:
                    - generic [ref=e126]: Factor
                    - textbox "Factor" [ref=e127]:
                      - /placeholder: such as 0.5 or 2
                  - generic [ref=e128]:
                    - generic [ref=e129]: Scale for
                    - combobox "Scale for" [ref=e131]:
                      - option "15 minutes"
                      - option "1 hour" [selected]
                      - option "4 hours"
                      - option "1 day"
                      - option "No end time"
                  - button "Apply" [disabled]
        - generic [ref=e132]:
          - generic [ref=e133]:
            - heading "Top rules" [level=2] [ref=e135]
            - generic [ref=e136]: By blocks, then would-blocks, in the last hour
          - paragraph [ref=e138]: No rule blocked a request in the last hour.
```

# Test source

```ts
  177 |     async statuses(path: string, count: number): Promise<number[]> {
  178 |         const statuses: number[] = [];
  179 |         for (let sent = 0; sent < count; sent++) {
  180 |             statuses.push((await this.get(path)).status());
  181 |         }
  182 | 
  183 |         return statuses;
  184 |     }
  185 | }
  186 | 
  187 | /** Runs an Artisan command in the Workbench app, with the server's environment, and answers its output. */
  188 | export function artisan(...args: string[]): string {
  189 |     return execFileSync('php', ['vendor/bin/testbench', ...args, '--no-interaction'], {
  190 |         cwd: root,
  191 |         env: serverEnvironment(),
  192 |         encoding: 'utf8',
  193 |     });
  194 | }
  195 | 
  196 | /** A test's case id, the start of its title: `RUL-07` for `RUL-07: …`. */
  197 | export function caseIdOf(title: string): string {
  198 |     return title.split(':')[0] ?? title;
  199 | }
  200 | 
  201 | /** A card of the dashboard, found by its heading. */
  202 | export function card(page: Page, title: string): Locator {
  203 |     return page.locator('[data-slot="card"]').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
  204 | }
  205 | 
  206 | /** The banner every page shows while bypass or a scale factor is on. */
  207 | export function emergencyBanner(page: Page): Locator {
  208 |     return page.getByRole('alert').filter({ hasText: 'An emergency setting is on' });
  209 | }
  210 | 
  211 | /** A number as the dashboard writes it in en-US, such as 12,345. */
  212 | export function numberIn(text: string | null): number {
  213 |     const match = /-?[\d,]+(\.\d+)?/.exec(text ?? '');
  214 |     if (match === null) {
  215 |         throw new Error(`No number in "${text}".`);
  216 |     }
  217 | 
  218 |     return Number(match[0].replaceAll(',', ''));
  219 | }
  220 | 
  221 | type Fixtures = {
  222 |     api: DashboardApi;
  223 |     rules: TestRules;
  224 |     exemptions: TestExemptions;
  225 |     traffic: Traffic;
  226 |     guard: void;
  227 |     calm: void;
  228 | };
  229 | 
  230 | export const test = base.extend<Fixtures>({
  231 |     api: async ({ request }, use) => {
  232 |         await use(await DashboardApi.open(request));
  233 |     },
  234 | 
  235 |     rules: async ({ api }, use) => {
  236 |         const rules = new TestRules(api);
  237 |         await use(rules);
  238 |         await rules.deleteAll();
  239 |     },
  240 | 
  241 |     exemptions: async ({ api }, use, testInfo) => {
  242 |         const exemptions = new TestExemptions(api, caseIdOf(testInfo.title));
  243 |         await use(exemptions);
  244 |         await exemptions.deleteAll();
  245 |     },
  246 | 
  247 |     traffic: async ({ request }, use) => {
  248 |         await use(new Traffic(request));
  249 |     },
  250 | 
  251 |     /**
  252 |      * Fails the test when its page logs a console error, throws, breaks the Content
  253 |      * Security Policy, or gets a server error from the dashboard.
  254 |      */
  255 |     guard: [
  256 |         async ({ page }, use) => {
  257 |             const problems: string[] = [];
  258 |             page.on('console', (message) => {
  259 |                 // The browser's own log of a response or a failed request, such as a 409 a test expects or a
  260 |                 // request it aborts, is no error of the page. Server errors are caught by their status below.
  261 |                 if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
  262 |                     problems.push(`console error: ${message.text()}`);
  263 |                 }
  264 |             });
  265 |             page.on('pageerror', (error) => problems.push(`uncaught error: ${error.message}`));
  266 |             page.on('response', (response) => {
  267 |                 if (response.status() >= 500 && response.url().startsWith(dashboard)) {
  268 |                     problems.push(`${response.status()} from ${response.url()}`);
  269 |                 }
  270 |             });
  271 |             await page.addInitScript(() => {
  272 |                 document.addEventListener('securitypolicyviolation', (event) => {
  273 |                     console.error(`CSP violation: ${event.violatedDirective} blocked ${event.blockedURI || 'an inline resource'}`);
  274 |                 });
  275 |             });
  276 |             await use();
> 277 |             expect(problems, 'the page logged no error, threw nothing, and broke no CSP rule').toEqual([]);
      |                                                                                                ^ Error: the page logged no error, threw nothing, and broke no CSP rule
  278 |         },
  279 |         { auto: true },
  280 |     ],
  281 | 
  282 |     /** Leaves bypass and scaling off after every test, even one that failed half-way. */
  283 |     calm: [
  284 |         async ({ api }, use) => {
  285 |             await use();
  286 |             await api.send('PUT', 'settings/bypass', { state: 'off' });
  287 |             await api.send('PUT', 'settings/scale', { factor: 'off' });
  288 |         },
  289 |         { auto: true },
  290 |     ],
  291 | });
  292 | 
```