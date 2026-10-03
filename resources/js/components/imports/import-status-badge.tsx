import { cn } from '@/lib/utils';
import type { ImportStatus } from '@/types';

const styles: Record<ImportStatus, string> = {
    pending: 'bg-muted text-muted-foreground',
    validating: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
    processing:
        'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    completed:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    completed_with_errors:
        'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
    cancelled: 'bg-muted text-muted-foreground line-through',
};

export function ImportStatusBadge({
    status,
    label,
}: {
    status: ImportStatus;
    label: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                styles[status],
            )}
            data-status={status}
        >
            {label}
        </span>
    );
}
