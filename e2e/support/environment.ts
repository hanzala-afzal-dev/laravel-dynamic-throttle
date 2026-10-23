import path from 'node:path';
import { fileURLToPath } from 'node:url';

/** The repository's root, where Composer and npm run. */
export const root = fileURLToPath(new URL('../..', import.meta.url));

export const port = Number(process.env.E2E_PORT ?? '8123');

export const baseURL = `http://127.0.0.1:${port}`;

/** The dashboard, at the package's default path. */
export const dashboard = `${baseURL}/dynamic-throttle`;

/**
 * One key prefix per run. Each run builds a fresh database, so rule ids start
 * again at 1, and an earlier run's counters in Redis would count against the new rules.
 * The runner sets it once, and its workers inherit it.
 */
export const prefix = (process.env.E2E_PREFIX ??= `e2e-${Date.now().toString(36)}:`);

/**
 * Stops the run before it starts on native Windows, where PHP's built-in server cannot
 * fork workers ("forking is not supported on this platform"), so it answers one request
 * at a time: each page's requests queue, and the tests time out one by one (issues #112
 * to #132). E2E_ALLOW_WINDOWS=1 runs them there anyway.
 */
export function requireParallelServer(platform: NodeJS.Platform, env: NodeJS.ProcessEnv): void {
    if (platform === 'win32' && env.E2E_ALLOW_WINDOWS !== '1') {
        throw new Error(
            "The browser tests need a server that answers requests in parallel, and PHP's built-in server answers one request at a time on Windows, " +
                'so they would time out. Run them in WSL 2, with the repository in its own file system (CONTRIBUTING.md), ' +
                'or set E2E_ALLOW_WINDOWS=1 to try anyway.',
        );
    }
}

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
