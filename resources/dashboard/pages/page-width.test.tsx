import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { AppLayout } from '@/components/layout/app-layout';
import { Pages } from '@/pages';
import { type Answers, manager, mockApi, renderAt } from '@/test/render';
import { ruleOf } from '@/test/rules';

/*
 * Issue #146: on a phone, the rules table made the Rules page as wide as the table, so
 * "New rule" sat off the screen. A grid without columns sizes its one column to its widest
 * content. Tailwind's grid-cols-1, repeat(1, minmax(0, 1fr)), lets that column shrink to the
 * screen, and a wide table then scrolls inside its card. jsdom has no layout, so the tests
 * check each page's grid for the class.
 */

const rule: Answers = { 'GET rules/1': { body: { rule: ruleOf(), exemptions: [] } } };

const pages: [string, string, Answers][] = [
    ['/', 'Overview', {}],
    ['/rules', 'Rules', {}],
    ['/rules/new', 'New rule', {}],
    ['/rules/1', 'API', rule],
    ['/rules/1/edit', 'Edit API', rule],
    ['/exemptions', 'Exemptions', {}],
    ['/activity', 'Activity', {}],
    ['/explain', 'Explain', {}],
    ['/audit', 'Audit log', {}],
    ['/settings', 'Settings', {}],
];

describe('the pages on a phone (issue #146)', () => {
    it.each(pages)("%s lets its content shrink to the screen's width", async (path, heading, answers) => {
        mockApi(manager(answers));
        renderAt(
            <AppLayout>
                <Pages />
            </AppLayout>,
            path,
        );

        const page = (await screen.findByRole('heading', { level: 1, name: heading }, { timeout: 5_000 })).closest('section');

        expect(page).toHaveClass('grid', 'grid-cols-1');
    });
});
