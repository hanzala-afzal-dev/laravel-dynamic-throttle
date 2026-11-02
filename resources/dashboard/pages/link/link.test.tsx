import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import LinkPage from '@/pages/link';
import { renderAt } from '@/test/render';

/* SPEC-034 AC-21 and AC-22: the link's page, which waits for "Continue". */

const TOKEN = 'k3J9xQ2mN8vR4tY7wE1aS5dF6gH0jK2lZ9xC3vB7n_-';

describe('the link\'s page', () => {
    it('AC-21: shows "Continue", and sends nothing before it is pressed', () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);

        renderAt(<LinkPage token={TOKEN} onOpened={vi.fn()} />, '/link');

        expect(screen.getByRole('heading', { level: 1, name: 'Open the Dynamic Throttle dashboard' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeEnabled();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('AC-22: opens the dashboard\'s plain address once the link is used', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 204 })));
        const opened = vi.fn();
        renderAt(<LinkPage token={TOKEN} onOpened={opened} />, '/link');

        await userEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(opened).toHaveBeenCalledWith('/dynamic-throttle');
    });

    it('AC-22: shows the server\'s message when the link cannot be used, and opens nothing', async () => {
        const message = 'This link is not valid: it was already used, it was revoked, or it has expired. Ask for a new one.';
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message }), { status: 403 })));
        const opened = vi.fn();
        renderAt(<LinkPage token={TOKEN} onOpened={opened} />, '/link');

        await userEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(message);
        expect(opened).not.toHaveBeenCalled();
    });

    it('AC-22: says to open the link again when the address has no token, such as after a reload', () => {
        vi.stubGlobal('fetch', vi.fn());
        renderAt(<LinkPage token={null} onOpened={vi.fn()} />, '/link');

        expect(screen.getByRole('alert')).toHaveTextContent('This address has no link in it. Open the link you were sent again.');
        expect(screen.queryByRole('button', { name: 'Continue' })).not.toBeInTheDocument();
    });
});
