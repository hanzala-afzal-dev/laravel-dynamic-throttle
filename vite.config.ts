import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

const source = fileURLToPath(new URL('./resources/dashboard', import.meta.url));

// The dashboard's build (ADR-0028): dist/ ships in the Composer package, and the
// package's asset route serves it, so the files sit flat in dist/ and import each
// other by relative URLs, whatever path the dashboard is served at.
export default defineConfig({
    root: source,
    base: './',
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: { '@': source },
    },
    build: {
        outDir: fileURLToPath(new URL('./dist', import.meta.url)),
        emptyOutDir: true,
        manifest: true,
        assetsDir: '',
        sourcemap: false,
        rollupOptions: {
            input: `${source}/main.tsx`,
        },
    },
    test: {
        environment: 'jsdom',
        setupFiles: [`${source}/test/setup.ts`],
        include: ['**/*.test.{ts,tsx}'],
        css: false,
    },
});
