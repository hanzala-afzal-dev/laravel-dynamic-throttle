/** A count in the browser's locale, such as 1,500. */
export function formatCount(value: number, locale?: string): string {
    return new Intl.NumberFormat(locale).format(value);
}
