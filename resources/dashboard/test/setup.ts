import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, vi } from 'vitest';
import { configureApi } from '@/lib/api';

// jsdom has no ResizeObserver, which Recharts' ResponsiveContainer needs (SPEC-021).
globalThis.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
};

// The shell's root and <meta> tags, as the page shell renders them (SPEC-020).
document.head.innerHTML = '<meta name="csrf-token" content="a-csrf-token"><meta name="csp-nonce" content="a-nonce">';
configureApi({ base: '/dynamic-throttle' });

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.useRealTimers();
    localStorage.clear();
    document.documentElement.className = '';
});
