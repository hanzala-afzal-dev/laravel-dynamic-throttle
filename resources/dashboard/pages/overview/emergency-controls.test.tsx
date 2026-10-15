import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { EmergencyControls } from '@/pages/overview/emergency-controls';
import { type Answer, manager, mockApi, renderAt, viewer } from '@/test/render';

/* SPEC-021 AC-9 to AC-12: bypass and the scale factor, on the Overview. */

const OFF = { bypass: { on: false, until: null }, scale: { factor: null, until: null } };
const BYPASS_ON = { bypass: { on: true, until: '2027-01-15T13:00:00+00:00' }, scale: { factor: null, until: null } };
const SCALED = { bypass: { on: false, until: null }, scale: { factor: '2', until: '2027-01-15T16:00:00+00:00' } };

/** A change the API asks to confirm, as SPEC-018 does, and accepts with confirm: true. */
function confirming(message: string): (body: unknown) => Answer {
    return (body) =>
        typeof body === 'object' && body !== null && 'confirm' in body && body.confirm === true
            ? { body: { changed: true, settings: OFF } }
            : { status: 409, body: { message } };
}

const puts = (sent: [string, string, unknown][], path: string) => sent.filter(([method, sentPath]) => method === 'PUT' && sentPath === path).map(([, , body]) => body);
const settingsReads = (sent: [string, string, unknown][]) => sent.filter(([method, path]) => method === 'GET' && path === 'settings').length;

describe('the emergency controls', () => {
    it('AC-9: turn bypass on for an hour, after the API\'s confirmation', async () => {
        const sent = mockApi(manager({ 'PUT settings/bypass': confirming('Bypass lifts every limit until 2027-01-15 13:00 UTC.') }));
        renderAt(<EmergencyControls />);

        await userEvent.click(await screen.findByRole('button', { name: 'Turn on bypass' }));
        const dialog = await screen.findByRole('alertdialog');

        expect(dialog).toHaveTextContent('Bypass lifts every limit until 2027-01-15 13:00 UTC.');

        await userEvent.click(within(dialog).getByRole('button', { name: 'Confirm' }));

        await waitFor(() => expect(puts(sent, 'settings/bypass')).toEqual([{ state: 'on', for: '1h' }, { state: 'on', for: '1h', confirm: true }]));
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });

    it('AC-9: send nothing more when the confirmation is cancelled', async () => {
        const sent = mockApi(manager({ 'PUT settings/bypass': confirming('Bypass lifts every limit until 2027-01-15 13:00 UTC.') }));
        renderAt(<EmergencyControls />);

        await userEvent.click(await screen.findByRole('button', { name: 'Turn on bypass' }));
        await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Cancel' }));

        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(puts(sent, 'settings/bypass')).toEqual([{ state: 'on', for: '1h' }]);
    });

    it('AC-9: send forever for no end time', async () => {
        const sent = mockApi(manager({ 'PUT settings/bypass': confirming('Bypass lifts every limit with no end time.') }));
        renderAt(<EmergencyControls />);

        await userEvent.selectOptions(await screen.findByLabelText('Bypass for'), 'No end time');
        await userEvent.click(screen.getByRole('button', { name: 'Turn on bypass' }));

        await waitFor(() => expect(puts(sent, 'settings/bypass')).toEqual([{ state: 'on', forever: true }]));
    });

    it('AC-9: show until when bypass is on, and turn it off', async () => {
        const sent = mockApi(manager({ 'GET settings': { body: BYPASS_ON }, 'PUT settings/bypass': { body: { changed: true, settings: OFF } } }));
        renderAt(<EmergencyControls />);

        expect(await screen.findByText(/^Bypass is on until/)).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Turn off bypass' }));

        await waitFor(() => expect(puts(sent, 'settings/bypass')).toEqual([{ state: 'off' }]));
    });

    it('AC-10: apply a typed factor for the chosen time', async () => {
        const sent = mockApi(manager({ 'PUT settings/scale': { body: { changed: true, settings: SCALED } } }));
        renderAt(<EmergencyControls />);

        await userEvent.type(await screen.findByLabelText('Factor'), '2');
        await userEvent.selectOptions(screen.getByLabelText('Scale for'), '4 hours');
        await userEvent.click(screen.getByRole('button', { name: 'Apply' }));

        await waitFor(() => expect(puts(sent, 'settings/scale')).toEqual([{ factor: '2', for: '4h' }]));
    });

    it('AC-10: ask to confirm a factor below 1, as the API says', async () => {
        const sent = mockApi(manager({ 'PUT settings/scale': confirming('Every limit is multiplied by 0.5 until 2027-01-15 13:00 UTC, so clients are limited sooner.') }));
        renderAt(<EmergencyControls />);

        await userEvent.type(await screen.findByLabelText('Factor'), '0.5');
        await userEvent.click(screen.getByRole('button', { name: 'Apply' }));
        const dialog = await screen.findByRole('alertdialog');

        expect(dialog).toHaveTextContent('so clients are limited sooner.');

        await userEvent.click(within(dialog).getByRole('button', { name: 'Confirm' }));

        await waitFor(() => expect(puts(sent, 'settings/scale')).toEqual([{ factor: '0.5', for: '1h' }, { factor: '0.5', for: '1h', confirm: true }]));
    });

    it('AC-10: show a 422\'s message under the factor', async () => {
        const message = 'The factor must be from 0.1 to 10, with at most two decimals, such as 1.5, got "11".';
        mockApi(manager({ 'PUT settings/scale': { status: 422, body: { message, errors: { factor: [message] } } } }));
        renderAt(<EmergencyControls />);

        await userEvent.type(await screen.findByLabelText('Factor'), '11');
        await userEvent.click(screen.getByRole('button', { name: 'Apply' }));

        await waitFor(() => expect(screen.getByLabelText('Factor')).toHaveAccessibleDescription(message));
        expect(screen.getByLabelText('Factor')).toHaveAttribute('aria-invalid', 'true');
    });

    it('AC-10: show the factor and its end, and turn scaling off', async () => {
        const sent = mockApi(manager({ 'GET settings': { body: SCALED }, 'PUT settings/scale': { body: { changed: true, settings: OFF } } }));
        renderAt(<EmergencyControls />);

        expect(await screen.findByText(/^Every limit is multiplied by 2 until/)).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Turn off scaling' }));

        await waitFor(() => expect(puts(sent, 'settings/scale')).toEqual([{ factor: 'off' }]));
    });

    it('AC-11: ask for the settings again after a change', async () => {
        const sent = mockApi(manager({ 'GET settings': { body: BYPASS_ON }, 'PUT settings/bypass': { body: { changed: true, settings: OFF } } }));
        renderAt(<EmergencyControls />);

        await userEvent.click(await screen.findByRole('button', { name: 'Turn off bypass' }));

        await waitFor(() => expect(settingsReads(sent)).toBe(2));
    });

    it('AC-12: show a viewer the state, and nothing that changes it', async () => {
        mockApi(viewer({ 'GET settings': { body: SCALED } }));
        renderAt(<EmergencyControls />);

        expect(await screen.findByText('Bypass is off.')).toBeInTheDocument();
        expect(screen.getByText(/^Every limit is multiplied by 2 until/)).toBeInTheDocument();
        await screen.findByRole('heading', { name: 'Emergency controls' });
        expect(screen.queryAllByRole('button')).toEqual([]);
        expect(screen.queryAllByRole('textbox')).toEqual([]);
        expect(screen.queryAllByRole('combobox')).toEqual([]);
    });
});
