import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    // PHP's tests hold fixture builds, which are not ours to lint.
    { ignores: ['dist/', 'vendor/', 'node_modules/', 'workbench/', 'tests/'] },
    {
        files: ['resources/dashboard/**/*.{ts,tsx}', 'vite.config.ts'],
        extends: [js.configs.recommended, ...tseslint.configs.recommended, reactHooks.configs.flat.recommended],
        languageOptions: { globals: globals.browser },
    },
    {
        // The browser tests (SPEC-032), which run in Node and drive Chromium.
        files: ['e2e/**/*.ts', 'playwright.config.ts'],
        extends: [js.configs.recommended, ...tseslint.configs.recommended],
        languageOptions: { globals: globals.node },
    },
    {
        files: ['scripts/**/*.mjs', 'e2e/**/*.mjs'],
        extends: [js.configs.recommended],
        languageOptions: { globals: globals.node },
    },
);
