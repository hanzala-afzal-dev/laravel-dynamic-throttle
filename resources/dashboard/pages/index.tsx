import { Link, Route, Switch } from 'wouter';

/** The dashboard's pages, by path under its base (SPEC-020): placeholders until each page's spec. */
export function Pages() {
    return (
        <Switch>
            <Route path="/">
                <Placeholder title="Overview" spec="SPEC-021" />
            </Route>
            <Route path="/rules">
                <Placeholder title="Rules" spec="SPEC-022" />
            </Route>
            <Route path="/rules/new">
                <Placeholder title="New rule" spec="SPEC-022" />
            </Route>
            <Route path="/rules/:id">
                <Placeholder title="Rule" spec="SPEC-022" />
            </Route>
            <Route path="/rules/:id/edit">
                <Placeholder title="Edit rule" spec="SPEC-022" />
            </Route>
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
