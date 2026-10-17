import { type Exemption, whoOf } from '@/lib/exemptions';

/** Who an exemption lets through: "IP 203.0.113.0/24", or "User 42" with its class on hover. */
export function ExemptionWho({ exemption }: { exemption: Exemption }) {
    const { kind, value, detail } = whoOf(exemption);

    return (
        <>
            <span className="text-muted-foreground">{kind}</span>{' '}
            <code className="font-mono text-xs" title={detail === null ? undefined : exemption.value}>
                {value}
            </code>
        </>
    );
}
