import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { CliCard } from '@/pages/settings/cli-card';
import { renderAt } from '@/test/render';

/* SPEC-031 AC-5: what to run when the dashboard itself is down. */

describe('the CLI card', () => {
    it('AC-5: lists the commands for bypass, scale, rules, status and health, and links to the runbook', () => {
        renderAt(<CliCard />, '/settings');

        const card = within(screen.getByRole('region', { name: 'When the dashboard is down' }));

        expect(card.getAllByRole('code').map((code) => code.textContent)).toEqual([
            'php artisan dynamic-throttle:bypass on --for=1h',
            'php artisan dynamic-throttle:bypass off',
            'php artisan dynamic-throttle:scale 0.5 --for=1h',
            'php artisan dynamic-throttle:scale off',
            'php artisan dynamic-throttle:list',
            'php artisan dynamic-throttle:disable-rule {id}',
            'php artisan dynamic-throttle:enable-rule {id}',
            'php artisan dynamic-throttle:status',
            'php artisan dynamic-throttle:health',
        ]);
        expect(card.getByRole('link', { name: 'The runbook' })).toHaveAttribute('href', 'https://github.com/hanzala-afzal-dev/laravel-dynamic-throttle/blob/main/docs/runbook.md');
    });
});
