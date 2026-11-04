import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    // PHP's tests hold fixture builds, which are not ours to lint, nor the docs site's build (ADR-0029).
    { ignores: ['dist/', 'vendor/', 'node_modules/', 'workbench/', 'tests/', 'docs/.vitepress/dist/', 'docs/.vitepress/cache/', 'e2e/test-results/', 'e2e/playwright-report/'] },
    {
        files: ['resources/dashboard/**/*.{ts,tsx}', 'vite.config.ts'],
        extends: [js.configs.recommended, ...tseslint.configs.recommended, reactHooks.configs.flat.recommended],
        languageOptions: { globals: globals.browser },
    },
    // The browser smoke test (SPEC-036), which runs in Node.
    {
        files: ['e2e/**/*.ts'],
        extends: [js.configs.recommended, ...tseslint.configs.recommended],
        languageOptions: { globals: globals.node },
    },
    {
        files: ['scripts/**/*.mjs'],
        extends: [js.configs.recommended],
        languageOptions: { globals: globals.node },
    },
);
