import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

// The status colours paint the health cards' "Healthy", "Warnings" and "Errors", which are
// large text, and the traffic chart's bars and the status dots, which are graphics: WCAG 2.1
// AA asks 3:1 against what they sit on, the cards and the page (SC 1.4.3 and 1.4.11).
const MINIMUM = 3;

// Read from disk: the tests' config turns CSS imports off, ?raw ones included.
const css = readFileSync(join(import.meta.dirname, 'styles.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');

/** Each block that sets the status colours: the light theme, and the dark one, for the class and for the system. */
const themes = [...css.matchAll(/([^{};]+)\{([^{}]*--warning:[^{}]*)\}/g)].map(([, selector, body]) => ({
    selector: (selector ?? '').trim(),
    tokens: Object.fromEntries([...(body ?? '').matchAll(/--([\w-]+):\s*oklch\(([^)]*)\)/g)].map(([, name, value]) => [name, value ?? ''])),
}));

/** An oklch() colour's linear sRGB channels, clipped to the sRGB gamut as a browser shows it. */
function linearSrgb(oklch: string): [number, number, number] {
    const [lightness, chroma, hue] = oklch.split(/\s+/).map(Number) as [number, number, number];
    const a = chroma * Math.cos((hue * Math.PI) / 180);
    const b = chroma * Math.sin((hue * Math.PI) / 180);
    const l = (lightness + 0.3963377774 * a + 0.2158037573 * b) ** 3;
    const m = (lightness - 0.1055613458 * a - 0.0638541728 * b) ** 3;
    const s = (lightness - 0.0894841775 * a - 1.291485548 * b) ** 3;
    const clip = (channel: number): number => Math.min(1, Math.max(0, channel));

    return [
        clip(4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s),
        clip(-1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s),
        clip(-0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s),
    ];
}

/** WCAG's contrast ratio between two colours. */
function contrast(first: string, second: string): number {
    const luminance = (oklch: string): number => {
        const [red, green, blue] = linearSrgb(oklch);

        return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
    };
    const [lighter, darker] = [luminance(first), luminance(second)].sort((x, y) => y - x) as [number, number];

    return (lighter + 0.05) / (darker + 0.05);
}

it('finds the light theme and the dark one', () => {
    expect(themes.map((theme) => theme.selector)).toEqual(expect.arrayContaining([':root', '.dark']));
});

describe.each(themes)('$selector', ({ tokens }) => {
    it.each(['warning', 'success', 'destructive'])('--%s has 3:1 against the cards and the page', (token) => {
        for (const surface of ['card', 'background']) {
            const ratio = contrast(tokens[token] ?? '', tokens[surface] ?? '');
            expect(ratio, `--${token} on --${surface}: ${ratio.toFixed(2)}:1`).toBeGreaterThanOrEqual(MINIMUM);
        }
    });
});
