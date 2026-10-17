import { lazy, Suspense } from 'react';
import { Link, Route, Switch } from 'wouter';
import { Skeleton } from '@/components/ui/skeleton';

/* Each page loads its own code when opened (ADR-0028 point 5). */
const Overview = lazy(() => import('@/pages/overview'));
const RulesList = lazy(() => import('@/pages/rules/list'));
const RuleForm = lazy(() => import('@/pages/rules/form'));

/** The dashboard's pages, by path under its base (SPEC-020): placeholders until each page's spec. */
export function Pages() {
    return (
        <Suspense fallback={<Loading />}>
            <Switch>
                <Route path="/">
                    <Overview />
                </Route>
                <Route path="/rules">
                    <RulesList />
                </Route>
                <Route path="/rules/new">
                    <RuleForm />
                </Route>
                <Route path="/rules/:id">
                    <Placeholder title="Rule" spec="SPEC-022" />
                </Route>
                <Route path="/rules/:id/edit">{(params) => <RuleForm id={Number(params.id)} />}</Route>
                <Route path="/exemptions">
                    <Placeholder title="Exemptions" spec="SPEC-023" />
                </Route>
                <Route path="/activity">
                    <Placeholder title="Activity" spec="SPEC-024" />
                </Route>
                <Route path="/explain">
                    <Placeholder title="Explain" spec="SPEC-026" />
                </Route>
                <Route path="/audit">
                    <Placeholder title="Audit log" spec="SPEC-025" />
                </Route>
                <Route path="/settings">
                    <Placeholder title="Settings" spec="SPEC-031" />
                </Route>
                <Route>
                    <NotFound />
                </Route>
            </Switch>
        </Suspense>
    );
}

function Placeholder({ title, spec }: { title: string; spec: string }) {
    return (
        <section>
            <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
            <p className="mt-2 text-sm text-muted-foreground">This page comes with {spec}.</p>
        </section>
    );
}

function NotFound() {
    return (
        <section>
            <h1 className="text-2xl font-semibold tracking-tight">Page not found</h1>
            <p className="mt-2 text-sm text-muted-foreground">
                <Link href="/" className="underline underline-offset-4">
                    Back to the overview
                </Link>
            </p>
        </section>
    );
}

function Loading() {
    return (
        <div className="grid gap-4" aria-busy="true" aria-label="Loading the page">
            <Skeleton className="h-8 w-48" />
            <Skeleton className="h-32 w-full" />
        </div>
    );
}
