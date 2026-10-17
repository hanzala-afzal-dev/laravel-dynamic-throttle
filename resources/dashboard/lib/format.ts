/** A count in the browser's locale, such as 1,500. */
export function formatCount(value: number, locale?: string): string {
    return new Intl.NumberFormat(locale).format(value);
}

/** A share, such as 4.6%, with at most one decimal. */
export function formatShare(part: number, whole: number, locale?: string): string {
    return new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 1 }).format(whole === 0 ? 0 : part / whole);
}
