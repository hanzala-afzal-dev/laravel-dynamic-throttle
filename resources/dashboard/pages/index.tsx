import { lazy, Suspense } from 'react';
import { Link, Route, Switch } from 'wouter';
import { Skeleton } from '@/components/ui/skeleton';

/* Each page loads its own code when opened (ADR-0028 point 5). */
const Overview = lazy(() => import('@/pages/overview'));
const RulesList = lazy(() => import('@/pages/rules/list'));
const RuleForm = lazy(() => import('@/pages/rules/form'));
const RulePage = lazy(() => import('@/pages/rules/rule'));
const ExemptionsList = lazy(() => import('@/pages/exemptions/list'));
const SettingsPage = lazy(() => import('@/pages/settings'));
const ActivityPage = lazy(() => import('@/pages/activity'));
const AuditLogPage = lazy(() => import('@/pages/audit'));
const ExplainPage = lazy(() => import('@/pages/explain'));

/** The dashboard's pages, by path under its base (SPEC-020). */
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
                <Route path="/rules/:id">{(params) => (/^[1-9]\d*$/.test(params.id) ? <RulePage id={Number(params.id)} /> : <NotFound />)}</Route>
                <Route path="/rules/:id/edit">{(params) => (/^[1-9]\d*$/.test(params.id) ? <RuleForm id={Number(params.id)} /> : <NotFound />)}</Route>
                <Route path="/exemptions">
                    <ExemptionsList />
                </Route>
                <Route path="/activity">
                    <ActivityPage />
                </Route>
                <Route path="/explain">
                    <ExplainPage />
                </Route>
                <Route path="/audit">
                    <AuditLogPage />
                </Route>
                <Route path="/settings">
                    <SettingsPage />
                </Route>
                <Route>
                    <NotFound />
                </Route>
            </Switch>
        </Suspense>
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
