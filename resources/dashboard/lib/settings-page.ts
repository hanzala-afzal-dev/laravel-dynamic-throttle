import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

/** A config value as GET api/config gives it (SPEC-031). */
export type ConfigValue = string | number | boolean | null | ConfigValue[] | { [key: string]: ConfigValue };

/** GET api/config: the configuration servers run with. */
export type EffectiveConfig = {
    driver: string | null;
    driver_error: string | null;
    manifest_store: string | null;
    config: Record<string, ConfigValue>;
    scopes: string[];
    strict: boolean;
};

export function useEffectiveConfig() {
    return useQuery({ queryKey: ['config'], queryFn: () => api<EffectiveConfig>('GET', 'config') });
}

/**
 * The config as groups of key and value: the top-level keys that hold values in
 * "General", and each top-level key that holds keys in a group of its own, its nested
 * keys joined with dots. A list stays one value.
 */
export function configGroups(config: Record<string, ConfigValue>): { name: string; rows: [string, string][] }[] {
    const general: [string, string][] = [];
    const groups: { name: string; rows: [string, string][] }[] = [];

    for (const [key, value] of Object.entries(config)) {
        if (isGroup(value)) {
            groups.push({ name: key, rows: flatten(value) });
        } else {
            general.push([key, shown(value)]);
        }
    }

    return [{ name: 'General', rows: general }, ...groups];
}

function flatten(group: Record<string, ConfigValue>, prefix = ''): [string, string][] {
    return Object.entries(group).flatMap(([key, value]): [string, string][] =>
        isGroup(value) && Object.keys(value).length > 0 ? flatten(value, `${prefix}${key}.`) : [[`${prefix}${key}`, shown(value)]],
    );
}

function isGroup(value: ConfigValue): value is Record<string, ConfigValue> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** A value as the config file would hold it: dt:, true, null, ["web"]. */
function shown(value: ConfigValue): string {
    return typeof value === 'string' ? value : JSON.stringify(value);
}

/** What each health check looks for, from the health guide's table (decision 5). */
export const CHECK_PURPOSES: Record<string, string> = {
    config: 'A config the counter driver cannot be built from, the kill switch off, a value the package replaces with its default, or a group or middleware that names nothing.',
    store: 'What the counter store guarantees. The file, apc and octane stores count per server, the database store writes on every limited request, and array and null are for tests.',
    'cache-clear': 'The Redis driver in the Redis database of a cache store, which php artisan cache:clear flushes, every limit with it.',
    'app-key': 'No APP_KEY, so identities are hashed without a key.',
    attached: 'dynamic.throttle on no route, and not global middleware, so no rule applies.',
    except: 'Horizon, Telescope, Pulse, Nova or Sanctum at a path except does not cover, so a rule can limit them.',
    'two-limiters': 'Routes that run Laravel\'s throttle too, which writes the same headers.',
    'middleware-order': 'Routes where authentication runs after dynamic.throttle, so user rules count your users as guests.',
    manifest: 'No manifest in the cache, so no rule applies. One the scheduled compile has not rewritten for 10 minutes. More than 500 rules or 1,000 exemptions.',
    emergency: 'Bypass or a scale factor left on with no end time, or with one more than 24 hours away.',
    metrics: 'Metrics not flushed for more than 10 minutes: the scheduler is not running.',
    guests: 'A user rule that saw mostly guests in the last hour: authentication the route check cannot see, or a rule on public routes.',
    'dominant-ip': 'One client IP behind half or more of the recent requests: usually a load balancer\'s, because the trusted proxies are not set.',
    ignored: 'Rules ignored in the last hour because more rules of their mode matched a request than matching.max_rules_per_request allows.',
};
