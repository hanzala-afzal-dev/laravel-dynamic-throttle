import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

/** The dashboard. Its frame and pages come with SPEC-020's later parts. */
export function App() {
    return (
        <main className="mx-auto max-w-xl p-8">
            <Card>
                <CardHeader>
                    <CardTitle>Dynamic Throttle</CardTitle>
                    <CardDescription>The dashboard is being built.</CardDescription>
                </CardHeader>
                <CardContent>
                    <Button variant="outline">Reload</Button>
                </CardContent>
            </Card>
        </main>
    );
}
