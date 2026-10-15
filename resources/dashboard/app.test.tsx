import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { App } from '@/app';
import { manager, mockApi, renderAt } from '@/test/render';

describe('App', () => {
    it('renders the frame around the overview', () => {
        mockApi(manager());
        renderAt(<App />);

        expect(screen.getByText('Dynamic Throttle')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Overview' })).toBeInTheDocument();
    });
});
