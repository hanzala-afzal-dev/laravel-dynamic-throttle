import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Pages } from '@/pages';
import { manager, mockApi, renderAt } from '@/test/render';

/* SPEC-021: the Overview, the first page, loaded on demand. */

describe('the Overview', () => {
    it('AC-3, AC-4: is the dashboard\'s first page, with the health and rules cards', async () => {
        mockApi(manager({ 'GET health': { body: { status: 'ok', checks: [] } }, 'GET rules/counts': { body: { enforcing: 1, dry_run: 0, disabled: 0 } } }));
        renderAt(<Pages />, '/');

        expect(await screen.findByRole('heading', { level: 1, name: 'Overview' })).toBeInTheDocument();
        expect(await screen.findByRole('heading', { name: 'Health' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Rules' })).toBeInTheDocument();
    });
});
