import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { EmergencyBanner } from '@/components/layout/emergency-banner';
import { manager, mockApi, renderAt, viewer } from '@/test/render';

/* SPEC-020 AC-9: the banner on every page while an emergency setting is on. */

const bypassOn = { 'GET settings': { body: { bypass: { on: true, until: '2027-01-15T14:00:00+00:00' }, scale: { factor: null, until: null } } } };
const scaled = { 'GET settings': { body: { bypass: { on: false, until: null }, scale: { factor: '0.5', until: null } } } };
const turnedOff = { body: { settings: { bypass: { on: false, until: null }, scale: { factor: null, until: null } }, changed: true, manifest: { compiled: true, version: 2 } } };

describe('the emergency banner', () => {
    it('AC-9: says until when bypass is on, and lets a manager turn it off', async () => {
        const sent = mockApi(manager({ ...bypassOn, 'PUT settings/bypass': turnedOff }));
        const user = userEvent.setup();
        renderAt(<EmergencyBanner />);

        expect(await screen.findByRole('alert')).toHaveTextContent('Bypass is on: no rule limits any request until');
        await user.click(await screen.findByRole('button', { name: 'Turn off bypass' }));

        await waitFor(() => expect(sent).toContainEqual(['PUT', 'settings/bypass', { state: 'off' }]));
    });

    it('AC-9: says a scale factor with no end time, and lets a manager turn it off', async () => {
        const sent = mockApi(manager({ ...scaled, 'PUT settings/scale': turnedOff }));
        const user = userEvent.setup();
        renderAt(<EmergencyBanner />);

        expect(await screen.findByRole('alert')).toHaveTextContent('Every limit is multiplied by 0.5, with no end time');
        await user.click(await screen.findByRole('button', { name: 'Turn off scaling' }));

        await waitFor(() => expect(sent).toContainEqual(['PUT', 'settings/scale', { factor: 'off' }]));
    });

    it('AC-9: shows a viewer the banner without a button', async () => {
        const sent = mockApi(viewer(bypassOn));
        renderAt(<EmergencyBanner />);

        expect(await screen.findByRole('alert')).toBeInTheDocument();
        await waitFor(() => expect(sent.map(([method, path]) => `${method} ${path}`)).toContain('GET me'));
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('AC-9: shows nothing while no emergency setting is on', async () => {
        const sent = mockApi(manager());
        renderAt(<EmergencyBanner />);

        await waitFor(() => expect(sent.map(([method, path]) => `${method} ${path}`)).toContain('GET settings'));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
});
