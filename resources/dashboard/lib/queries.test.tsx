import { act } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useSettings } from '@/lib/queries';
import { manager, mockApi, renderAt } from '@/test/render';

/* SPEC-020 AC-11: live data polls every 5 seconds, only while the page is visible. */

function Settings() {
    useSettings();

    return null;
}

function setVisibility(state: 'visible' | 'hidden') {
    Object.defineProperty(document, 'visibilityState', { value: state, configurable: true });
    window.dispatchEvent(new Event('visibilitychange'));
}

describe('live queries', () => {
    afterEach(() => {
        setVisibility('visible');
    });

    it('AC-11: poll every 5 seconds while the page is visible, and not while it is hidden', async () => {
        vi.useFakeTimers();
        const sent = mockApi(manager());
        const reads = () => sent.filter(([method, path]) => method === 'GET' && path === 'settings').length;
        renderAt(<Settings />);

        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(reads()).toBe(1);

        await act(() => vi.advanceTimersByTimeAsync(5_000));
        expect(reads()).toBe(2);

        setVisibility('hidden');
        await act(() => vi.advanceTimersByTimeAsync(20_000));
        expect(reads()).toBe(2);
    });
});
