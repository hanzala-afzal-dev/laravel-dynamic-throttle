import path from 'node:path';
import { fileURLToPath } from 'node:url';

/** The repository's root, where Composer and npm run. */
export const root = fileURLToPath(new URL('../..', import.meta.url));

export const port = Number(process.env.E2E_PORT ?? '8123');

export const baseURL = `http://127.0.0.1:${port}`;

/** The dashboard, at the package's default path. */
export const dashboard = `${baseURL}/dynamic-throttle`;

/**
 * One key prefix per run (SPEC-032). Each run builds a fresh database, so rule ids start
 * again at 1, and an earlier run's counters in Redis would count against the new rules.
 * The runner sets it once, and its workers inherit it.
 */
export const prefix = (process.env.E2E_PREFIX ??= `e2e-${Date.now().toString(36)}:`);

/**
 * The environment the server and Artisan run with: the Workbench app on Redis, with this
 * run's prefix, rules that apply at once, and the dashboard's own limit off.
 */
export function serverEnvironment(): NodeJS.ProcessEnv {
    return {
        ...process.env,
        // Testbench reads the environment from $_ENV and $_SERVER only, and the built-in
        // server's workers fill $_ENV only with this setting, which this folder's ini adds.
        PHP_INI_SCAN_DIR: `${process.env.PHP_INI_SCAN_DIR ?? ''}${path.delimiter}${path.join(root, 'e2e', 'support', 'php')}`,
        APP_ENV: 'local',
        CACHE_STORE: 'redis',
        REDIS_HOST: process.env.REDIS_HOST ?? '127.0.0.1',
        REDIS_PORT: process.env.REDIS_PORT ?? '6379',
        DYNAMIC_THROTTLE_PREFIX: prefix,
        // Every request reads the manifest, so a rule applies from the next request on.
        DYNAMIC_THROTTLE_MANIFEST_REFRESH: '0',
        // The Workbench app turns the dashboard's own 120 requests a minute off for the suite.
        WORKBENCH_E2E: '1',
        PHP_CLI_SERVER_WORKERS: process.env.PHP_CLI_SERVER_WORKERS ?? '4',
    };
}
