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
