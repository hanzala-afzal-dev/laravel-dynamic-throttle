import { AppLayout } from '@/components/layout/app-layout';
import { Pages } from '@/pages';

/** The dashboard: its frame around the page the path names (SPEC-020). */
export function App() {
    return (
        <AppLayout>
            <Pages />
        </AppLayout>
    );
}
