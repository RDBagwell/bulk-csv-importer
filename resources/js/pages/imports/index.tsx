import { Head, Link } from '@inertiajs/react';
import { FileUp } from 'lucide-react';
import Heading from '@/components/heading';
import { ImportStatusBadge } from '@/components/imports/import-status-badge';
import { Button } from '@/components/ui/button';
import { formatBytes, formatDuration, formatNumber } from '@/lib/format';
import { create, index, show } from '@/routes/imports';
import type { ImportSummary, Paginated } from '@/types';

export default function ImportsIndex({
    imports,
}: {
    imports: Paginated<ImportSummary>;
}) {
    return (
        <>
            <Head title="Imports" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Imports"
                        description="Your CSV imports, newest first."
                    />
                    <Button asChild>
                        <Link href={create()}>
                            <FileUp /> New import
                        </Link>
                    </Button>
                </div>

                {imports.data.length === 0 ? (
                    <EmptyState />
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[720px] text-sm">
                            <thead className="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        File
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Imported
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Failed
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Duration
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Owner
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {imports.data.map((item) => (
                                    <tr
                                        key={item.id}
                                        className="hover:bg-muted/30"
                                    >
                                        <td className="max-w-[280px] px-4 py-3">
                                            <Link
                                                href={show(item.id)}
                                                className="block truncate font-medium hover:underline"
                                                title={item.filename}
                                            >
                                                {item.filename}
                                            </Link>
                                            <span className="text-xs text-muted-foreground">
                                                {formatBytes(item.size_bytes)}
                                                {item.created_at && (
                                                    <>
                                                        {' '}
                                                        ·{' '}
                                                        {new Date(
                                                            item.created_at,
                                                        ).toLocaleString()}
                                                    </>
                                                )}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <ImportStatusBadge
                                                status={item.status}
                                                label={item.status_label}
                                            />
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {formatNumber(item.rows_imported)}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {formatNumber(item.rows_failed)}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {item.elapsed_seconds === null
                                                ? '—'
                                                : formatDuration(
                                                      item.elapsed_seconds,
                                                  )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {item.owner?.name ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {imports.meta.last_page > 1 && (
                    <nav
                        className="flex items-center justify-between text-sm"
                        aria-label="Pagination"
                    >
                        <span className="text-muted-foreground">
                            {imports.meta.from}–{imports.meta.to} of{' '}
                            {imports.meta.total}
                        </span>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!imports.links.prev}
                                asChild={!!imports.links.prev}
                            >
                                {imports.links.prev ? (
                                    <Link href={imports.links.prev}>
                                        Previous
                                    </Link>
                                ) : (
                                    <span>Previous</span>
                                )}
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!imports.links.next}
                                asChild={!!imports.links.next}
                            >
                                {imports.links.next ? (
                                    <Link href={imports.links.next}>Next</Link>
                                ) : (
                                    <span>Next</span>
                                )}
                            </Button>
                        </div>
                    </nav>
                )}
            </div>
        </>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-12 text-center">
            <FileUp className="size-8 text-muted-foreground" />
            <div>
                <p className="font-medium">No imports yet</p>
                <p className="text-sm text-muted-foreground">
                    Upload a CSV file to start your first import.
                </p>
            </div>
            <Button asChild>
                <Link href={create()}>Upload a file</Link>
            </Button>
        </div>
    );
}

ImportsIndex.layout = {
    breadcrumbs: [{ title: 'Imports', href: index() }],
};
