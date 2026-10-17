import type { Rule } from '@/lib/rules';

/** A rule as api/rules gives it (SPEC-017), enforcing 60 requests a minute per IP on api/*. */
export function ruleOf(overrides: Partial<Rule> = {}): Rule {
    return {
        id: 1,
        name: 'API',
        description: null,
        method: '*',
        pattern: 'api/*',
        scope: 'ip',
        scope_parameter: null,
        max_attempts: 60,
        window_seconds: 60,
        priority: 100,
        mode: 'enforce',
        on_failure: 'open',
        on_missing_identity: 'ip',
        response_override: null,
        enabled: true,
        created_at: '2027-01-15T12:00:00+00:00',
        updated_at: '2027-01-15T12:00:00+00:00',
        ...overrides,
    };
}

/** A page of rules, as Laravel's paginator gives it. */
export function pageOf(rules: Rule[], page = 1, lastPage = 1) {
    return { data: rules, current_page: page, last_page: lastPage, per_page: 100, total: rules.length };
}
