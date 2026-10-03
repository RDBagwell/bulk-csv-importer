import { formatDuration, formatNumber } from '@/lib/format';
import { computeProgress } from '@/lib/import-progress';
import type { ImportSummary } from '@/types';

type Props = {
    import: Pick<
        ImportSummary,
        | 'status'
        | 'is_active'
        | 'size_bytes'
        | 'bytes_processed'
        | 'total_rows'
        | 'rows_processed'
        | 'rows_failed'
        | 'elapsed_seconds'
    >;
};

export function ImportProgress({ import: summary }: Props) {
    const progress = computeProgress(summary);

    return (
        <div className="space-y-3">
            <div
                role="progressbar"
                aria-label="Import progress"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={progress.percent}
                className="h-3 w-full overflow-hidden rounded-full bg-muted"
            >
                <div
                    className="h-full rounded-full bg-primary transition-[width] duration-500"
                    style={{ width: `${progress.percent}%` }}
                />
            </div>

            <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                <Stat label="Progress" value={`${progress.percent}%`} />
                <Stat
                    label="Rows"
                    value={
                        progress.rowsTotal === null
                            ? formatNumber(summary.rows_processed)
                            : `${formatNumber(summary.rows_processed)} of ${progress.rowsTotalIsEstimate ? '~' : ''}${formatNumber(progress.rowsTotal)}`
                    }
                />
                <Stat
                    label="Rows / second"
                    value={
                        progress.rowsPerSecond === null
                            ? '—'
                            : formatNumber(progress.rowsPerSecond)
                    }
                />
                <Stat
                    label={summary.is_active ? 'Time left' : 'Duration'}
                    value={
                        summary.is_active
                            ? progress.etaSeconds === null
                                ? '—'
                                : `~${formatDuration(progress.etaSeconds)}`
                            : summary.elapsed_seconds === null
                              ? '—'
                              : formatDuration(summary.elapsed_seconds)
                    }
                />
            </dl>
        </div>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium tabular-nums">{value}</dd>
        </div>
    );
}
