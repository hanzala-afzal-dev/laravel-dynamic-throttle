import { getNonce } from 'get-nonce';
import * as React from 'react';
import * as RechartsPrimitive from 'recharts';
import { cn } from '@/lib/utils';

/*
 * shadcn/ui's chart, kept to what the dashboard uses. Each series' colour is a
 * CSS variable, --color-{key}, set by a <style> element that carries the page's
 * nonce, so the strict CSP allows it (SPEC-021 decision 5). The colours are
 * themselves variables, such as var(--destructive), so they follow the theme.
 */

export type ChartConfig = Record<string, { label: string; color: string }>;

const ChartContext = React.createContext<{ config: ChartConfig } | null>(null);

export function useChart(): { config: ChartConfig } {
    const context = React.useContext(ChartContext);

    if (context === null) {
        throw new Error('useChart must be used within a <ChartContainer />');
    }

    return context;
}

function ChartContainer({
    className,
    children,
    config,
    ...props
}: React.ComponentProps<'div'> & {
    config: ChartConfig;
    children: React.ComponentProps<typeof RechartsPrimitive.ResponsiveContainer>['children'];
}) {
    // From React alone, kept to characters a CSS selector takes as they are.
    const chartId = `chart-${React.useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;

    return (
        <ChartContext.Provider value={{ config }}>
            <div
                data-slot="chart"
                data-chart={chartId}
                className={cn(
                    "flex aspect-video justify-center text-xs [&_.recharts-cartesian-axis-tick_text]:fill-muted-foreground [&_.recharts-cartesian-grid_line[stroke='#ccc']]:stroke-border/50 [&_.recharts-layer]:outline-hidden [&_.recharts-rectangle.recharts-tooltip-cursor]:fill-muted [&_.recharts-surface]:outline-hidden",
                    className,
                )}
                {...props}
            >
                <ChartStyle id={chartId} config={config} />
                <RechartsPrimitive.ResponsiveContainer>{children}</RechartsPrimitive.ResponsiveContainer>
            </div>
        </ChartContext.Provider>
    );
}

function ChartStyle({ id, config }: { id: string; config: ChartConfig }) {
    const variables = Object.entries(config)
        .map(([key, item]) => `  --color-${key}: ${item.color};`)
        .join('\n');

    return <style nonce={getNonce()}>{`[data-chart=${id}] {\n${variables}\n}`}</style>;
}

const ChartTooltip = RechartsPrimitive.Tooltip;

/** The series' colours and labels, anywhere near the chart: it reads each colour from the config itself. */
function ChartLegend({ config, className }: { config: ChartConfig; className?: string }) {
    return (
        <ul className={cn('flex flex-wrap items-center justify-center gap-4 text-xs', className)}>
            {Object.entries(config).map(([key, item]) => (
                <li key={key} className="flex items-center gap-1.5">
                    {/* A style property set through the DOM, which the CSP allows, unlike a style attribute in the HTML. */}
                    <span className="size-2.5 shrink-0 rounded-[2px]" style={{ backgroundColor: item.color }} aria-hidden />
                    {item.label}
                </li>
            ))}
        </ul>
    );
}

export { ChartContainer, ChartLegend, ChartStyle, ChartTooltip };
