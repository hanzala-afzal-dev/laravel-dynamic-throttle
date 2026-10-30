/**
 * The package's logo, art/logo.svg: a red D whose curve is a gauge. Its scale takes the
 * text's colour and the hub's hole the sidebar's, so it suits both themes. A test checks
 * that it draws the shapes of art/logo.svg. Beside the name, screen readers skip it.
 */
export function Logo({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 512 512" className={className} aria-hidden="true" data-slot="logo">
            <path d="M112 96 H268 A160 160 0 0 1 268 416 H112 Z" fill="none" stroke="#ef4444" strokeWidth={40} strokeLinejoin="round" />
            <line x1="150.8" y1="231.7" x2="178.9" y2="242.0" stroke="currentColor" strokeWidth={12} strokeLinecap="round" />
            <line x1="176.8" y1="190.8" x2="198.0" y2="212.0" stroke="currentColor" strokeWidth={12} strokeLinecap="round" />
            <line x1="217.7" y1="164.8" x2="228.0" y2="192.9" stroke="currentColor" strokeWidth={12} strokeLinecap="round" />
            <line x1="265.8" y1="158.4" x2="263.1" y2="188.3" stroke="currentColor" strokeWidth={12} strokeLinecap="round" />
            <line x1="312.0" y1="173.0" x2="297.0" y2="199.0" stroke="currentColor" strokeWidth={12} strokeLinecap="round" />
            <line x1="347.7" y1="205.8" x2="323.2" y2="223.0" stroke="currentColor" strokeWidth={12} strokeLinecap="round" />
            <line x1="366.3" y1="250.6" x2="336.8" y2="255.8" stroke="currentColor" strokeWidth={12} strokeLinecap="round" />
            <line x1="364.2" y1="299.0" x2="335.2" y2="291.2" stroke="#ef4444" strokeWidth={12} strokeLinecap="round" />
            <line x1="341.8" y1="342.0" x2="318.8" y2="322.7" stroke="#ef4444" strokeWidth={12} strokeLinecap="round" />
            <line x1="256" y1="270" x2="315.1" y2="199.5" stroke="#ef4444" strokeWidth={18} strokeLinecap="round" />
            <circle cx="256" cy="270" r="24" fill="#ef4444" />
            <circle cx="256" cy="270" r="9" className="fill-sidebar" />
        </svg>
    );
}
