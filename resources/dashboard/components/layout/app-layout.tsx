import { Activity, Gauge, LayoutDashboard, ListChecks, Monitor, Moon, ScrollText, SearchCode, Settings, ShieldCheck, Sun } from 'lucide-react';
import type { ReactNode } from 'react';
import { Link, useLocation } from 'wouter';
import { EmergencyBanner } from '@/components/layout/emergency-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useMe } from '@/lib/queries';
import { useTheme } from '@/lib/theme';
import { cn } from '@/lib/utils';

const PAGES = [
    { href: '/', label: 'Overview', icon: LayoutDashboard },
    { href: '/rules', label: 'Rules', icon: ListChecks },
    { href: '/exemptions', label: 'Exemptions', icon: ShieldCheck },
    { href: '/activity', label: 'Activity', icon: Activity },
    { href: '/explain', label: 'Explain', icon: SearchCode },
    { href: '/audit', label: 'Audit log', icon: ScrollText },
    { href: '/settings', label: 'Settings', icon: Settings },
] as const;

const THEME_ICONS = { system: Monitor, light: Sun, dark: Moon } as const;

/** The frame every page shares (SPEC-020): the sidebar, the header and the emergency banner. */
export function AppLayout({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-svh flex-col md:flex-row">
            <Sidebar />
            <div className="flex min-w-0 flex-1 flex-col">
                <Header />
                <main className="flex-1 p-4 md:p-8">
                    <EmergencyBanner />
                    {children}
                </main>
            </div>
        </div>
    );
}

function Sidebar() {
    const [location] = useLocation();

    return (
        <aside className="border-b bg-sidebar text-sidebar-foreground md:w-60 md:shrink-0 md:border-r md:border-b-0">
            <div className="flex h-14 items-center gap-2 px-4 font-semibold">
                <Gauge className="size-5" aria-hidden />
                Dynamic Throttle
            </div>
            <nav aria-label="Pages" className="flex gap-1 overflow-x-auto px-2 pb-2 md:flex-col md:pb-4">
                {PAGES.map(({ href, label, icon: Icon }) => {
                    const active = href === '/' ? location === '/' : location === href || location.startsWith(`${href}/`);

                    return (
                        <Link
                            key={href}
                            href={href}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'flex items-center gap-2 whitespace-nowrap rounded-md px-3 py-2 text-sm text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                                active && 'bg-sidebar-accent font-medium text-sidebar-accent-foreground',
                            )}
                        >
                            <Icon className="size-4" aria-hidden />
                            {label}
                        </Link>
                    );
                })}
            </nav>
        </aside>
    );
}

function Header() {
    const me = useMe().data;
    const [theme, nextTheme] = useTheme();
    const ThemeIcon = THEME_ICONS[theme];

    return (
        <header className="flex h-14 items-center justify-end gap-2 border-b px-4 md:px-8">
            {me !== undefined && (
                <>
                    <Badge variant={me.environment === 'production' ? 'destructive' : 'secondary'}>{me.environment}</Badge>
                    <Badge variant="outline">{me.can.manage ? 'Manager' : 'Viewer'}</Badge>
                </>
            )}
            <Button variant="ghost" size="icon" onClick={nextTheme} aria-label={`Theme: ${theme}`} title={`Theme: ${theme}`}>
                <ThemeIcon />
            </Button>
        </header>
    );
}
