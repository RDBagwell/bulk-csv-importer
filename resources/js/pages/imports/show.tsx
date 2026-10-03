import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Ban, Download, RotateCcw, WifiOff } from 'lucide-react';
import { ConfirmAction } from '@/components/imports/confirm-action';
import { ImportProgress } from '@/components/imports/import-progress';
import { ImportStatusBadge } from '@/components/imports/import-status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useImportStatus } from '@/hooks/use-import-status';
import { formatBytes, formatNumber } from '@/lib/format';
import { cancel, errors, index, retry, show } from '@/routes/imports';
import type { ImportErrorRow, ImportSummary } from '@/types';

type Props = {
    import: ImportSummary;
    latestErrors: ImportErrorRow[];
};

export default function ShowImport(props: Props) {
    const {
        import: summary,
        latest_errors: latestErrors,
        connectionLost,
    } = useImportStatus(props.import, props.latestErrors);

    const post = (url: string) =>
        new Promise<void>((resolve) => {
            router.post(
                url,
                {},
                { preserveScroll: true, onFinish: () => resolve() },
            );
        });

    return (
        <>
            <Head title={summary.filename} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1">
                        <h1
                            className="truncate text-xl font-semibold tracking-tight"
                            title={summary.filename}
                        >
                            {summary.filename}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <ImportStatusBadge
                                status={summary.status}
                                label={summary.status_label}
                            />
                            <span>{formatBytes(summary.size_bytes)}</span>
                            <span>
                                ·{' '}
                                {summary.mode === 'parallel'
                                    ? `${summary.chunk_count} chunks in parallel`
                                    : 'sequential'}
                            </span>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {summary.can.cancel && (
                            <ConfirmAction
                                trigger={
                                    <Button variant="outline">
                                        <Ban /> Cancel
                                    </Button>
                                }
                                title="Cancel this import?"
                                description="Remaining chunks will not be processed. Rows already imported are kept."
                                confirmLabel="Cancel import"
                                destructive
                                onConfirm={() => post(cancel(summary.id).url)}
                            />
                        )}
                        {summary.can.retry && (
                            <ConfirmAction
                                trigger={
                                    <Button variant="outline">
                                        <RotateCcw /> Retry failed chunks
                                    </Button>
                                }
                                title="Retry the failed chunks?"
                                description="Only the chunks that failed are run again, from where they stopped. Rows already imported are not duplicated."
                                confirmLabel="Retry"
                                onConfirm={() => post(retry(summary.id).url)}
                            />
                        )}
                        {summary.can.download_errors && (
                            <Button variant="outline" asChild>
                                <a href={errors(summary.id).url}>
                                    <Download /> Error report
                                </a>
                            </Button>
                        )}
                    </div>
                </header>

                {connectionLost && (
                    <Alert>
                        <WifiOff />
                        <AlertTitle>Live updates paused</AlertTitle>
                        <AlertDescription>
                            Can't reach the server. Still retrying; the import
                            keeps running.
                        </AlertDescription>
                    </Alert>
                )}

                {summary.failure_reason && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertTitle>
                            {summary.status === 'failed'
                                ? 'Import failed'
                                : 'Note'}
                        </AlertTitle>
                        <AlertDescription>
                            {summary.failure_reason}
                        </AlertDescription>
                    </Alert>
                )}

                <section className="rounded-xl border p-4 md:p-6">
                    <ImportProgress import={summary} />
                </section>

                <section className="grid gap-4 sm:grid-cols-3">
                    <Counter label="Imported" value={summary.rows_imported} />
                    <Counter
                        label="Failed rows"
                        value={summary.rows_failed}
                        tone={summary.rows_failed > 0 ? 'warning' : undefined}
                    />
                    <Counter label="Rows read" value={summary.rows_processed} />
                </section>

                <section className="space-y-3">
                    <div className="flex items-baseline justify-between gap-4">
                        <h2 className="font-semibold">Latest errors</h2>
                        {summary.rows_failed > summary.errors_stored && (
                            <p className="text-xs text-muted-foreground">
                                Showing stored errors only: the first{' '}
                                {formatNumber(summary.max_stored_errors)} are
                                kept, the rest are counted.
                            </p>
                        )}
                    </div>
                    {latestErrors.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                            {summary.is_active
                                ? 'No errors so far.'
                                : 'No errors.'}
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[640px] text-sm">
                                <thead className="bg-muted/50 text-left text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-2 font-medium">
                                            Line
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Column
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Problem
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Row
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {latestErrors.map((error) => (
                                        <tr key={error.id}>
                                            <td className="px-4 py-2 tabular-nums">
                                                {formatNumber(
                                                    error.line_number,
                                                )}
                                            </td>
                                            <td className="px-4 py-2">
                                                <code>
                                                    {error.column ?? '—'}
                                                </code>
                                            </td>
                                            <td className="px-4 py-2">
                                                {error.message}
                                            </td>
                                            <td
                                                className="max-w-[320px] truncate px-4 py-2 font-mono text-xs text-muted-foreground"
                                                title={error.raw_excerpt}
                                            >
                                                {error.raw_excerpt}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

function Counter({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone?: 'warning';
}) {
    return (
        <div className="rounded-xl border p-4">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p
                className={`text-2xl font-semibold tabular-nums ${tone === 'warning' ? 'text-amber-600 dark:text-amber-400' : ''}`}
            >
                {formatNumber(value)}
            </p>
        </div>
    );
}

ShowImport.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Imports', href: index() },
        { title: props.import.filename, href: show(props.import.id) },
    ],
});
