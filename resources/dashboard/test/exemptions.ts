import type { Exemption } from '@/lib/exemptions';

/** An exemption as api/exemptions gives it (SPEC-017): the IP 203.0.113.10, from every rule, with no end. */
export function exemptionOf(overrides: Partial<Exemption> = {}): Exemption {
    return {
        id: 1,
        rule_id: null,
        subject: 'ip',
        subject_parameter: null,
        value: '203.0.113.10',
        reason: null,
        expires_at: null,
        active: true,
        created_at: '2027-01-15T12:00:00+00:00',
        updated_at: '2027-01-15T12:00:00+00:00',
        ...overrides,
    };
}
