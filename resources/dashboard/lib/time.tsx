/**
 * A time in the browser's zone, with its UTC time on hover (SPEC-020). The zone and
 * the locale default to the browser's.
 */
export function formatTime(value: string, options: { timeZone?: string; locale?: string } = {}): { local: string; utc: string } {
    const date = new Date(value);
    const format = (timeZone?: string) => new Intl.DateTimeFormat(options.locale, { dateStyle: 'medium', timeStyle: 'short', timeZone }).format(date);

    return { local: format(options.timeZone), utc: `${format('UTC')} UTC` };
}

export function Time({ value, timeZone, locale }: { value: string; timeZone?: string; locale?: string }) {
    const { local, utc } = formatTime(value, { timeZone, locale });

    return (
        <time dateTime={value} title={utc}>
            {local}
        </time>
    );
}
