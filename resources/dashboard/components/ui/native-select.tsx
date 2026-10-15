import { ChevronDown } from 'lucide-react';
import type * as React from 'react';
import { cn } from '@/lib/utils';

/** A styled <select>: the browser's own list, keyboard and screen reader support. */
function NativeSelect({ className, ...props }: React.ComponentProps<'select'>) {
    return (
        <div className="relative w-fit" data-slot="native-select-wrapper">
            <select
                data-slot="native-select"
                className={cn(
                    'h-9 w-full min-w-0 appearance-none rounded-md border border-input bg-transparent py-1 pr-8 pl-3 text-sm shadow-xs transition-[color,box-shadow] outline-none disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30 [&>option]:bg-popover [&>option]:text-popover-foreground',
                    'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    className,
                )}
                {...props}
            />
            <ChevronDown className="pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
        </div>
    );
}

export { NativeSelect };
