import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Logo } from '@/components/logo';

/* The sidebar's logo is art/logo.svg drawn in React, with ticks in the text's colour: the same shapes. */

const art = new DOMParser().parseFromString(readFileSync(join(import.meta.dirname, '../../../art/logo.svg'), 'utf8'), 'image/svg+xml');

/** Each shape's geometry, in document order. */
function shapes(root: ParentNode): string[] {
    return [...root.querySelectorAll('path, line, circle')].map((shape) =>
        [shape.tagName.toLowerCase(), ...['d', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r'].filter((name) => shape.hasAttribute(name)).map((name) => `${name}=${shape.getAttribute(name)}`)].join(' '),
    );
}

describe('the logo', () => {
    it('draws the shapes of art/logo.svg', () => {
        const { container } = render(<Logo />);

        expect(shapes(container)).toEqual(shapes(art));
        expect(shapes(art)).toHaveLength(13);
    });

    it('draws the scale in the text\'s colour, but for its red end, and hides from screen readers beside the name', () => {
        const { container } = render(<Logo />);
        const svg = container.querySelector('svg');

        expect(container.querySelectorAll('line[stroke="currentColor"]')).toHaveLength(7);
        expect(svg).toHaveAttribute('aria-hidden', 'true');
    });
});
