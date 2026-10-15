import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

/** shadcn/ui's class helper: joins classes, the last Tailwind class of a kind wins. */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
