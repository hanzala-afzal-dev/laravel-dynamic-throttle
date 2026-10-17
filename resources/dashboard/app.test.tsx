import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { App } from '@/app';

describe('App', () => {
    it('renders the dashboard', () => {
        render(<App />);

        expect(screen.getByText('Dynamic Throttle')).toBeInTheDocument();
    });
});
