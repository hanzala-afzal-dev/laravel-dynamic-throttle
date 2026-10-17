import { useEffect, useState } from 'react';

/**
 * The theme: the system's, which CSS follows with no class on <html>, or light or
 * dark, which a class forces (SPEC-020 decision 5). The browser remembers it.
 */
export type Theme = 'system' | 'light' | 'dark';

const KEY = 'dynamic-throttle.theme';

const NEXT: Record<Theme, Theme> = { system: 'light', light: 'dark', dark: 'system' };

export function storedTheme(): Theme {
    try {
        const stored = localStorage.getItem(KEY);

        return stored === 'light' || stored === 'dark' ? stored : 'system';
    } catch {
        return 'system';
    }
}

export function applyTheme(theme: Theme): void {
    const html = document.documentElement;
    html.classList.remove('light', 'dark');

    if (theme !== 'system') {
        html.classList.add(theme);
    }
}

/** The theme, and a function that moves to the next: system → light → dark → system. */
export function useTheme(): [Theme, () => void] {
    const [theme, setTheme] = useState<Theme>(storedTheme);

    useEffect(() => {
        applyTheme(theme);

        try {
            localStorage.setItem(KEY, theme);
        } catch {
            // Private windows may refuse storage: the theme still applies for the visit.
        }
    }, [theme]);

    return [theme, () => setTheme(NEXT[theme])];
}
