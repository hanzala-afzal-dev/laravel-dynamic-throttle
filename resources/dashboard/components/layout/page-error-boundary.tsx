import { TriangleAlert } from 'lucide-react';
import { Component, type ReactNode } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';

type State = { error: Error | null };

/**
 * A page that fails to render shows its error inside the frame, rather than blank
 * the dashboard (SPEC-020 AC-18). Keyed by the path, so another page starts afresh.
 */
export class PageErrorBoundary extends Component<{ children: ReactNode }, State> {
    override state: State = { error: null };

    static getDerivedStateFromError(error: unknown): State {
        return { error: error instanceof Error ? error : new Error(String(error)) };
    }

    override render() {
        if (this.state.error === null) {
            return this.props.children;
        }

        return (
            <Alert variant="destructive">
                <TriangleAlert />
                <AlertDescription>
                    <p>This page failed: {this.state.error.message.replace(/\.$/, '')}. Reload the page, or open another.</p>
                </AlertDescription>
            </Alert>
        );
    }
}
