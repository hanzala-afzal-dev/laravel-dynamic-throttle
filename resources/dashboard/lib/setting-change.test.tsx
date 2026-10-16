import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it } from 'vitest';
import { type SettingChange, useSettingChange } from '@/lib/setting-change';
import { type Answer, mockApi, type Sent } from '@/test/render';

/* SPEC-021 AC-14 to AC-16: changing bypass or the scale factor, as the controls and the banner do. */

const BYPASS_ON: SettingChange = { path: 'settings/bypass', body: { state: 'on', for: '1h' } };
const MESSAGE = 'Bypass lifts every limit until 2027-01-15 13:00 UTC.';

function changing() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;

    return renderHook(() => useSettingChange(), { wrapper }).result;
}

/** Asks to confirm a change without "confirm", and accepts it with "confirm": true. */
function confirming(body: unknown): Answer {
    return typeof body === 'object' && body !== null && 'confirm' in body ? { body: { changed: true, manifest: { compiled: true, version: 2 } } } : { status: 409, body: { message: MESSAGE } };
}

const puts = (sent: Sent) => sent.filter(([method]) => method === 'PUT').map(([, , body]) => body);

describe('a setting change', () => {
    it('AC-16: confirms the change the 409 answered, once, however often Confirm is clicked', async () => {
        const sent = mockApi({ 'PUT settings/bypass': confirming });
        const change = changing();

        act(() => change.current.send(BYPASS_ON));
        await waitFor(() => expect([change.current.asking, change.current.confirming?.message]).toEqual([true, MESSAGE]));
        act(() => {
            change.current.confirm();
            change.current.confirm();
        });

        await waitFor(() => expect(change.current.pending).toBe(false));
        expect(puts(sent)).toEqual([{ state: 'on', for: '1h' }, { state: 'on', for: '1h', confirm: true }]);
        expect(change.current.asking).toBe(false);
    });

    it('AC-16: sends nothing when Confirm comes after the dialog closed', async () => {
        const sent = mockApi({ 'PUT settings/bypass': confirming });
        const change = changing();

        act(() => change.current.send(BYPASS_ON));
        await waitFor(() => expect(change.current.asking).toBe(true));
        act(() => change.current.close());
        act(() => change.current.confirm());

        expect(puts(sent)).toEqual([{ state: 'on', for: '1h' }]);
    });

    it('AC-16: shows a 409 that answers a confirmed change, and hides the one that asked', async () => {
        mockApi({ 'PUT settings/bypass': { status: 409, body: { message: MESSAGE } } });
        const change = changing();

        act(() => change.current.send(BYPASS_ON));
        await waitFor(() => expect(change.current.asking).toBe(true));

        expect(change.current.error).toBeNull();

        act(() => change.current.confirm());

        await waitFor(() => expect(change.current.error?.message).toBe(MESSAGE));
    });

    it('AC-14: says when the server could not be reached', async () => {
        mockApi({
            'PUT settings/bypass': () => {
                throw new TypeError('Failed to fetch');
            },
        });
        const change = changing();

        act(() => change.current.send(BYPASS_ON));

        await waitFor(() => expect(change.current.error?.message).toBe('The server could not be reached: check the connection, then try again.'));
    });

    it('AC-15: warns when the change is saved but the manifest did not compile', async () => {
        mockApi({ 'PUT settings/bypass': { body: { changed: true, manifest: { compiled: false, error: 'Connection refused' } } } });
        const change = changing();

        act(() => change.current.send({ path: 'settings/bypass', body: { state: 'off' } }));

        await waitFor(() => expect(change.current.warning).toBe('Saved, but servers keep the previous manifest until a compile succeeds: Connection refused'));
        expect(change.current.error).toBeNull();
    });
});
