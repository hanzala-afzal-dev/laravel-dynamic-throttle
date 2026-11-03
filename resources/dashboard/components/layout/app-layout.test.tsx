import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { AppLayout } from '@/components/layout/app-layout';
import { Pages } from '@/pages';
import { formatTime } from '@/lib/time';
import { manager, mockApi, renderAt, viewer } from '@/test/render';
import logo from '../../../../art/logo.svg?url';

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

    it('shows the logo beside the name in the sidebar', () => {
        mockApi(manager());
        dashboardAt('/');

        expect(screen.getByText('Dynamic Throttle').querySelector('svg[data-slot="logo"]')).not.toBeNull();
    });

    it('makes the logo the browser tab\'s icon, in place of the empty one the page starts with', () => {
        document.head.innerHTML = '<link rel="icon" href="data:,">';
        mockApi(manager());
        dashboardAt('/');

        expect(document.head.querySelectorAll('link[rel="icon"]')).toHaveLength(1);
        expect(document.head.querySelector('link[rel="icon"]')).toHaveAttribute('href', logo);
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

/* SPEC-034 AC-23: in a link's session, the header names the link and when it ends. */
describe('the header in a link\'s session', () => {
    const link = { name: 'alice, incident 42', manage: false, expires_at: '2027-01-01T01:00:00+00:00' };

    it('AC-23: names the link, and when it ends in the browser\'s time zone', async () => {
        mockApi(viewer({ 'GET me': { body: { can: { view: true, manage: false }, environment: 'production', link } } }));
        dashboardAt('/');

        const line = await screen.findByText(/^Link for alice, incident 42, until/);

        expect(line.querySelector('time')).toHaveAttribute('dateTime', link.expires_at);
        expect(line.querySelector('time')).toHaveTextContent(formatTime(link.expires_at).local);
    });

    it('AC-23: says nothing of a link in a session without one', async () => {
        mockApi(manager({ 'GET me': { body: { can: { view: true, manage: true }, environment: 'production', link: null } } }));
        dashboardAt('/');

        expect(await screen.findByText('Manager')).toBeInTheDocument();
        expect(screen.queryByText(/^Link for/)).not.toBeInTheDocument();
    });
});

