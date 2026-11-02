import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { App } from '@/app';
import { manager, mockApi, renderAt } from '@/test/render';

describe('App', () => {
    it('renders the frame around the overview', async () => {
        mockApi(manager());
        renderAt(<App />);

        expect(screen.getByText('Dynamic Throttle')).toBeInTheDocument();
        expect(await screen.findByRole('heading', { level: 1, name: 'Overview' }, { timeout: 5_000 })).toBeInTheDocument();
    });

    it('AC-17: keeps the frame when the API answers with a login page', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('<!doctype html><title>Log in</title>', { status: 200, headers: { 'Content-Type': 'text/html' } })));
        const { client } = renderAt(<App />);

        await waitFor(() => expect([client.getQueryState(['me'])?.status, client.getQueryState(['settings'])?.status]).toEqual(['error', 'error']));

        expect(screen.getByText('Dynamic Throttle')).toBeInTheDocument();
        expect(await screen.findByRole('heading', { level: 1, name: 'Overview' }, { timeout: 5_000 })).toBeInTheDocument();
    });

    it('SPEC-034 AC-21: shows the link\'s page at /link, outside the frame, without asking who the user is', async () => {
        const sent = mockApi(manager());
        renderAt(<App linkToken="k3J9xQ2mN8vR4tY7wE1aS5dF6gH0jK2lZ9xC3vB7n_-" />, '/link');

        expect(await screen.findByRole('button', { name: 'Continue' }, { timeout: 5_000 })).toBeInTheDocument();
        expect(screen.queryByRole('navigation', { name: 'Pages' })).not.toBeInTheDocument();
        expect(sent).toEqual([]);
    });
});
