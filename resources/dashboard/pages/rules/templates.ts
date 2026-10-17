import type { Rule } from '@/lib/rules';

/** The fields a template or a duplicate starts the form from. */
export type RuleDraft = Omit<Rule, 'id' | 'created_at' | 'updated_at'>;

/** A blank rule: 60 requests a minute per IP on no pattern yet, in dry-run (SPEC-022 decision 2). */
export const BLANK: RuleDraft = {
    name: '',
    description: null,
    method: '*',
    pattern: '',
    scope: 'ip',
    scope_parameter: null,
    max_attempts: 60,
    window_seconds: 60,
    priority: 100,
    mode: 'dry_run',
    on_failure: 'open',
    on_missing_identity: 'ip',
    response_override: null,
    enabled: true,
};

/**
 * Starting values for common limits (SPEC-022 decision 3). They live in the page:
 * saving one is an ordinary new rule, in dry-run like every new rule.
 */
export const TEMPLATES: { key: string; label: string; draft: RuleDraft }[] = [
    {
        key: 'login',
        label: 'Login',
        draft: { ...BLANK, name: 'Login', method: 'POST', pattern: 'login', max_attempts: 5, window_seconds: 60, priority: 10, response_override: { message: 'Too many login attempts. Try again in a minute.' } },
    },
    {
        key: 'password-reset',
        label: 'Password reset',
        draft: { ...BLANK, name: 'Password reset', method: 'POST', pattern: 'forgot-password', max_attempts: 3, window_seconds: 600, priority: 10 },
    },
    {
        key: 'sign-up',
        label: 'Sign-up',
        draft: { ...BLANK, name: 'Sign-up', method: 'POST', pattern: 'register', max_attempts: 10, window_seconds: 3_600, priority: 10 },
    },
    {
        key: 'api-token',
        label: 'API per token',
        draft: { ...BLANK, name: 'API per token', pattern: 'api/*', scope: 'api_token', max_attempts: 600, window_seconds: 60, priority: 50 },
    },
    {
        key: 'api-user',
        label: 'API per user',
        draft: { ...BLANK, name: 'API per user', pattern: 'api/*', scope: 'user', max_attempts: 120, window_seconds: 60, priority: 50 },
    },
    {
        key: 'expensive',
        label: 'Expensive endpoint',
        draft: { ...BLANK, name: 'Exports', method: 'GET', pattern: 'exports/*', scope: 'user', max_attempts: 5, window_seconds: 3_600, priority: 20 },
    },
    { key: 'blank', label: 'Blank', draft: BLANK },
];
