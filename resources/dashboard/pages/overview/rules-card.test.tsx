import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { RulesCard } from '@/pages/overview/rules-card';
import { mockApi, renderAt } from '@/test/render';

/* SPEC-021 AC-4: the rules card, from api/rules/counts. */

describe('the rules card', () => {
    it('AC-4: shows the enabled rules by mode and the disabled ones, in the browser\'s locale', async () => {
        mockApi({ 'GET rules/counts': { body: { enforcing: 2, dry_run: 1, disabled: 1500 } } });
        renderAt(<RulesCard />);

        expect(await screen.findByText('3')).toBeInTheDocument();
        expect(screen.getByText('enabled')).toBeInTheDocument();
        expect(screen.getByText('2 enforcing, 1 dry-run')).toBeInTheDocument();
        expect(screen.getByText('1,500 disabled')).toBeInTheDocument();
    });

    it('AC-4: links to the rules', async () => {
        mockApi({ 'GET rules/counts': { body: { enforcing: 0, dry_run: 0, disabled: 0 } } });
        renderAt(<RulesCard />);

        expect(await screen.findByRole('link', { name: 'See the rules' })).toHaveAttribute('href', '/rules');
    });
});
