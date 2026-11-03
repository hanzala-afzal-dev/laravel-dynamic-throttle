import { describe, expect, it } from 'vitest';
import { AUDIT_ACTIONS, actionWords } from '@/lib/audit';
import type { AuditEntry } from '@/lib/queries';

/* SPEC-034: the dashboard links' actions, in the Audit log page's words and its filter. */

function entry(action: string): AuditEntry {
    return { id: 1, actor: 'cli:hanzala', action, target: 'link:7', before: null, after: {}, ip: null, user_agent: null, created_at: '2027-01-01T00:00:00+00:00' };
}

describe('the audit log\'s words for links', () => {
    it('names a link made, used and ended', () => {
        expect(['link.create', 'link.use', 'link.revoke'].map((action) => actionWords(entry(action)))).toEqual(['Link made', 'Link used', 'Link ended']);
    });

    it('offers the links\' actions in the filter', () => {
        expect(Object.keys(AUDIT_ACTIONS)).toEqual(expect.arrayContaining(['link.create', 'link.use', 'link.revoke']));
    });
});
