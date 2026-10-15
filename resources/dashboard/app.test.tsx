import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { App } from '@/app';
import { manager, mockApi, renderAt } from '@/test/render';

describe('App', () => {
    it('renders the frame around the overview', async () => {
        mockApi(manager());
        renderAt(<App />);

        expect(screen.getByText('Dynamic Throttle')).toBeInTheDocument();
        expect(await screen.findByRole('heading', { level: 1, name: 'Overview' })).toBeInTheDocument();
    });

    it('AC-17: keeps the frame when the API answers with a login page', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('<!doctype html><title>Log in</title>', { status: 200, headers: { 'Content-Type': 'text/html' } })));
        const { client } = renderAt(<App />);

        await waitFor(() => expect([client.getQueryState(['me'])?.status, client.getQueryState(['settings'])?.status]).toEqual(['error', 'error']));

        expect(screen.getByText('Dynamic Throttle')).toBeInTheDocument();
        expect(await screen.findByRole('heading', { level: 1, name: 'Overview' })).toBeInTheDocument();
    });
});
