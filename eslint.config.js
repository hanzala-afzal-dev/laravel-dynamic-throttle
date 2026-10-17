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
        files: ['scripts/**/*.mjs'],
        extends: [js.configs.recommended],
        languageOptions: { globals: globals.node },
    },
);
