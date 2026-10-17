import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, vi } from 'vitest';
import { configureApi } from '@/lib/api';

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
