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
