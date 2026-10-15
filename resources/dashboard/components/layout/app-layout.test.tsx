import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { AppLayout } from '@/components/layout/app-layout';
import { Pages } from '@/pages';
import { manager, mockApi, renderAt, viewer } from '@/test/render';

/* SPEC-020 AC-7 and AC-8: the sidebar, the header, the role and the theme. */

function dashboardAt(path: string) {
    return renderAt(
        <AppLayout>
            <Pages />
        </AppLayout>,
        path,
    );
}

describe('the layout', () => {
    it('AC-7: lists the seven pages, and marks the active one', () => {
        mockApi(manager());
        dashboardAt('/rules');

        const nav = screen.getByRole('navigation', { name: 'Pages' });

        expect(within(nav).getAllByRole('link').map((link) => link.textContent)).toEqual([
            'Overview',
            'Rules',
            'Exemptions',
            'Activity',
            'Explain',
            'Audit log',
            'Settings',
        ]);
        expect(within(nav).getByRole('link', { name: 'Rules' })).toHaveAttribute('aria-current', 'page');
        expect(within(nav).getByRole('link', { name: 'Overview' })).not.toHaveAttribute('aria-current');
    });

    it('AC-7: marks Rules on a rule\'s own page', () => {
        mockApi(manager());
        dashboardAt('/rules/12');

        expect(within(screen.getByRole('navigation', { name: 'Pages' })).getByRole('link', { name: 'Rules' })).toHaveAttribute('aria-current', 'page');
    });

    it('AC-7: says the page is not found for an unknown path', () => {
        mockApi(manager());
        dashboardAt('/nope');

        expect(screen.getByRole('heading', { name: 'Page not found' })).toBeInTheDocument();
    });

    it('AC-8: shows a manager\'s role and the app\'s environment', async () => {
        mockApi(manager());
        dashboardAt('/');

        expect(await screen.findByText('Manager')).toBeInTheDocument();
        expect(screen.getByText('production')).toBeInTheDocument();
    });

    it('AC-8: shows a viewer\'s role', async () => {
        mockApi(viewer());
        dashboardAt('/');

        expect(await screen.findByText('Viewer')).toBeInTheDocument();
        expect(screen.getByText('local')).toBeInTheDocument();
    });

    it('AC-8: cycles the theme from system to light to dark, sets the class on <html>, and remembers it', async () => {
        mockApi(manager());
        const user = userEvent.setup();
        dashboardAt('/');
        const html = document.documentElement;

        await user.click(screen.getByRole('button', { name: 'Theme: system' }));
        expect([html.className, localStorage.getItem('dynamic-throttle.theme')]).toEqual(['light', 'light']);

        await user.click(screen.getByRole('button', { name: 'Theme: light' }));
        expect([html.className, localStorage.getItem('dynamic-throttle.theme')]).toEqual(['dark', 'dark']);

        await user.click(screen.getByRole('button', { name: 'Theme: dark' }));
        expect([html.className, localStorage.getItem('dynamic-throttle.theme')]).toEqual(['', 'system']);
        expect(screen.getByRole('button', { name: 'Theme: system' })).toBeInTheDocument();
    });

    it('AC-8: starts from the remembered theme', () => {
        localStorage.setItem('dynamic-throttle.theme', 'dark');
        mockApi(manager());
        dashboardAt('/');

        expect(document.documentElement.className).toBe('dark');
        expect(screen.getByRole('button', { name: 'Theme: dark' })).toBeInTheDocument();
    });
});
