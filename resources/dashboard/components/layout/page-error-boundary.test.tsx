import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Route, Switch } from 'wouter';
import { AppLayout } from '@/components/layout/app-layout';
import { manager, mockApi, renderAt } from '@/test/render';

/* SPEC-020 AC-18: a page that fails to render leaves the frame standing. */

function Broken(): never {
    throw new Error('Cannot read properties of null (reading \'bypass\')');
}

describe('a page that fails', () => {
    it('AC-18: shows its error inside the frame, and another page clears it', async () => {
        const logged = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        mockApi(manager());
        renderAt(
            <AppLayout>
                <Switch>
                    <Route path="/">
                        <Broken />
                    </Route>
                    <Route path="/rules">
                        <h1>Rules</h1>
                    </Route>
                </Switch>
            </AppLayout>,
        );

        expect(screen.getByRole('alert')).toHaveTextContent('This page failed: Cannot read properties of null (reading \'bypass\'). Reload the page, or open another.');

        await userEvent.click(within(screen.getByRole('navigation', { name: 'Pages' })).getByRole('link', { name: 'Rules' }));

        expect(screen.getByRole('heading', { name: 'Rules' })).toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        logged.mockRestore();
    });
});
