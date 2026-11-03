import { lazy, Suspense } from 'react';
import { useRoute } from 'wouter';
import { AppLayout } from '@/components/layout/app-layout';
import { Pages } from '@/pages';

/* The link's page loads its own code, as each page does (ADR-0028 point 5). */
const LinkPage = lazy(() => import('@/pages/link'));

/**
 * The dashboard: its frame around the page the path names (SPEC-020), or, at /link,
 * the link's page alone, before any session lets the frame ask who the user is (SPEC-034).
 */
export function App({ linkToken = null }: { linkToken?: string | null }) {
    const [isLink] = useRoute('/link');

    if (isLink) {
        return (
            <Suspense fallback={null}>
                <LinkPage token={linkToken} />
            </Suspense>
        );
    }

    return (
        <AppLayout>
            <Pages />
        </AppLayout>
    );
}
