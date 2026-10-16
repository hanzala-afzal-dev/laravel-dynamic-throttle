import { render } from '@testing-library/react';
import { setNonce } from 'get-nonce';
import { afterEach, describe, expect, it } from 'vitest';
import { ChartContainer } from '@/components/ui/chart';

/* SPEC-021 AC-17: the chart's runtime <style> carries the page's nonce, so the strict CSP allows it. */

describe('the chart', () => {
    afterEach(() => {
        setNonce('');
    });

    it('AC-17: gives its colours\' <style> the page\'s nonce', () => {
        setNonce('a-nonce');
        render(
            <ChartContainer config={{ blocks: { label: 'Blocks', color: 'var(--destructive)' } }}>
                <div />
            </ChartContainer>,
        );
        const style = document.querySelector('style');

        expect(style?.getAttribute('nonce')).toBe('a-nonce');
        expect(style?.textContent).toContain('--color-blocks: var(--destructive);');
    });
});
