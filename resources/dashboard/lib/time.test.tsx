import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Time } from '@/lib/time';

/* SPEC-020 AC-12: times in the browser's zone, with UTC on hover. */

describe('times', () => {
    it('AC-12: shows a time in the browser\'s zone, with its UTC time as the title', () => {
        render(<Time value="2027-01-15T12:30:00+00:00" timeZone="Asia/Karachi" locale="en-GB" />);

        const time = screen.getByText('15 Jan 2027, 17:30');

        expect(time.tagName).toBe('TIME');
        expect(time).toHaveAttribute('title', '15 Jan 2027, 12:30 UTC');
        expect(time).toHaveAttribute('datetime', '2027-01-15T12:30:00+00:00');
    });
});
