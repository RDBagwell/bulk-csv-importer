import type { ImportSummary } from '@/types';

export type ProgressView = {
    /** 0–100, from bytes read (rows are not known up front). */
    percent: number;
    /** Exact once the file has been scanned or finished; otherwise estimated from bytes. */
    rowsTotal: number | null;
    rowsTotalIsEstimate: boolean;
    rowsPerSecond: number | null;
    etaSeconds: number | null;
};

type ProgressInput = Pick<
    ImportSummary,
    | 'status'
    | 'is_active'
    | 'size_bytes'
    | 'bytes_processed'
    | 'total_rows'
    | 'rows_processed'
    | 'elapsed_seconds'
>;

const clamp = (value: number, min: number, max: number) =>
    Math.min(max, Math.max(min, value));

export function computeProgress(input: ProgressInput): ProgressView {
    const completed =
        input.status === 'completed' ||
        input.status === 'completed_with_errors';

    const fraction = completed
        ? 1
        : input.size_bytes > 0
          ? clamp(input.bytes_processed / input.size_bytes, 0, 1)
          : 0;

    let rowsTotal = input.total_rows;
    let rowsTotalIsEstimate = false;

    if (rowsTotal === null && fraction > 0 && input.rows_processed > 0) {
        rowsTotal = Math.round(input.rows_processed / fraction);
        rowsTotalIsEstimate = true;
    }

    const elapsed = input.elapsed_seconds ?? 0;
    const rowsPerSecond =
        elapsed > 0 && input.rows_processed > 0
            ? input.rows_processed / elapsed
            : null;

    // Bytes are the one measure that is always known, so the ETA projects
    // the remaining bytes at the rate seen so far.
    const etaSeconds =
        input.is_active && elapsed > 0 && fraction > 0 && fraction < 1
            ? (elapsed * (1 - fraction)) / fraction
            : null;

    return {
        percent: Math.floor(fraction * 1000) / 10,
        rowsTotal,
        rowsTotalIsEstimate,
        rowsPerSecond,
        etaSeconds,
    };
}
