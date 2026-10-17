/** A matched rule, as POST api/explain gives it (SPEC-014). */
export type ExplainedRule = {
    id: string;
    name: string;
    method: string;
    pattern: string;
    priority: number;
    mode: 'enforce' | 'dry_run';
    limit: number;
    max_attempts: number;
    window_seconds: number;
    on_failure: 'open' | 'closed';
    scope: string;
    scope_parameter: string | null;
    status: 'counts' | 'exempt' | 'skipped' | 'ignored' | 'failed' | 'not_reached';
    identity: string | null;
    hash: string | null;
    exemption: string | null;
    error: string | null;
    counter: { count: number; remaining: number; next: 'allowed' | 'limited'; retry_after: number | null } | null;
};

/** What POST api/explain answers: dynamic-throttle:explain --json (SPEC-014, SPEC-017). */
export type Explanation = {
    request: { method: string; path: string; ip: string; user: string | null; guard: string | null; headers: string[] };
    manifest: { version: number; rules: number; exemptions: number } | null;
    settings: { bypass: { until: string | null } | null; scale: { factor: string; until: string | null } | null };
    route: { uri: string; name: string | null; middleware: string[]; throttled: boolean; after_auth: boolean } | null;
    rules: ExplainedRule[];
    counters: { read: boolean; error: string | null };
    decision: { outcome: 'allowed' | 'rejected' | 'failed' | 'unavailable' | 'untouched' | 'unknown'; reason: string | null; rule: string | null; headers: Record<string, number> };
};

/** The form as typed: headers one "Name: value" a line. */
export type ExplainForm = { method: string; path: string; ip: string; user: string; guard: string; headers: string };

export const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as const;

/** The body of POST api/explain: empty optional fields as null (SPEC-026 AC-1). */
export function explainBody(form: ExplainForm) {
    const optional = (value: string) => (value.trim() === '' ? null : value.trim());
    const headers = form.headers
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');

    return { method: form.method, path: form.path.trim(), ip: optional(form.ip), user: optional(form.user), guard: optional(form.guard), headers: headers.length === 0 ? null : headers };
}

/** Why a request goes through untouched, in words (SPEC-014's reasons). */
export const UNTOUCHED: Record<string, string> = {
    not_attached: 'no route runs dynamic.throttle for this request.',
    disabled: 'the package is off (dynamic-throttle.enabled).',
    own_route: "it is one of the dashboard's own routes.",
    except: 'except lists this path.',
    bypass: 'bypass is on.',
    no_rule: 'no rule matches this request.',
};

/** What a rule does with the request, in words (SPEC-014's statuses). */
export const STATUSES: Record<ExplainedRule['status'], string> = {
    counts: 'Counts it',
    exempt: 'Exempt',
    skipped: 'Skipped: the request has no identity for it',
    ignored: 'Ignored: over the cap of rules per request',
    failed: 'Failed',
    not_reached: 'Not reached: an earlier rule failed closed',
};

/** A rule's status, with its exemption or its error when it has one. */
export function statusWords(rule: ExplainedRule): string {
    const detail = rule.exemption ?? rule.error;

    return detail === null ? STATUSES[rule.status] : `${STATUSES[rule.status]}: ${detail}`;
}

/** A rule's limit under the scale factor, per window: "5 per 60 s, scaled from 10". */
export function limitWords(rule: ExplainedRule): string {
    const limit = `${rule.limit} per ${rule.window_seconds} s`;

    return rule.limit === rule.max_attempts ? limit : `${limit}, scaled from ${rule.max_attempts}`;
}

/** A counter as read: "3 counted, 2 left, next allowed", or the wait when the next is limited. */
export function counterWords(counter: NonNullable<ExplainedRule['counter']>): string {
    const next = counter.next === 'allowed' ? 'next allowed' : `next limited for ${counter.retry_after ?? 0} s`;

    return `${counter.count} counted, ${counter.remaining} left, ${next}`;
}
