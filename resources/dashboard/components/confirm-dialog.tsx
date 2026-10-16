import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import type { ConfirmedChange } from '@/lib/confirmed-change';

/** The API's 409, asking to confirm a change, with Confirm and Cancel (SPEC-021 decision 8). */
export function ConfirmDialog({ change, note }: { change: Pick<ConfirmedChange, 'asking' | 'confirming' | 'confirm' | 'close'>; note?: string }) {
    return (
        <AlertDialog open={change.asking} onOpenChange={(open) => !open && change.close()}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Confirm the change</AlertDialogTitle>
                    <AlertDialogDescription>
                        {change.confirming?.message}
                        {note === undefined ? null : ` ${note}`}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction onClick={change.confirm}>Confirm</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/** Why a change failed, or that it was saved but did not compile (SPEC-021 AC-14, AC-15). */
export function ChangeOutcome({ change }: { change: Pick<ConfirmedChange, 'error' | 'warning'> }) {
    if (change.error !== null) {
        return <p className="text-sm text-destructive">{change.error.message}</p>;
    }

    return change.warning === null ? null : <p className="text-sm font-medium text-amber-700 dark:text-warning">{change.warning}</p>;
}
