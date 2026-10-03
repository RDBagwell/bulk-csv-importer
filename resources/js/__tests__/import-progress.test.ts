import { describe, expect, it } from 'vitest';
import { formatDuration } from '@/lib/format';
import { computeProgress } from '@/lib/import-progress';

const base = {
    status: 'processing' as const,
    is_active: true,
    size_bytes: 1000,
    bytes_processed: 0,
    total_rows: null,
    rows_processed: 0,
    elapsed_seconds: 0,
};

describe('computeProgress', () => {
    it('starts at zero with nothing to estimate', () => {
        expect(computeProgress(base)).toEqual({
            percent: 0,
            rowsTotal: null,
            rowsTotalIsEstimate: false,
            rowsPerSecond: null,
            etaSeconds: null,
        });
    });

    it('estimates the row total from bytes until the count is known', () => {
        const view = computeProgress({
            ...base,
            bytes_processed: 250,
            rows_processed: 5_000,
            elapsed_seconds: 10,
        });

        expect(view.percent).toBe(25);
        expect(view.rowsTotal).toBe(20_000);
        expect(view.rowsTotalIsEstimate).toBe(true);
        expect(view.rowsPerSecond).toBe(500);
        expect(view.etaSeconds).toBe(30);
    });

    it('uses the exact total once the splitter has counted it', () => {
        const view = computeProgress({
            ...base,
            bytes_processed: 500,
            rows_processed: 900,
            total_rows: 2_000,
            elapsed_seconds: 3,
        });

        expect(view.rowsTotal).toBe(2_000);
        expect(view.rowsTotalIsEstimate).toBe(false);
    });

    it('shows 100% and no ETA when finished', () => {
        const view = computeProgress({
            ...base,
            status: 'completed_with_errors',
            is_active: false,
            bytes_processed: 990,
            rows_processed: 2_000,
            total_rows: 2_000,
            elapsed_seconds: 4,
        });

        expect(view.percent).toBe(100);
        expect(view.etaSeconds).toBeNull();
    });

    it('keeps partial progress for cancelled imports', () => {
        const view = computeProgress({
            ...base,
            status: 'cancelled',
            is_active: false,
            bytes_processed: 400,
            rows_processed: 10,
            elapsed_seconds: 2,
        });

        expect(view.percent).toBe(40);
        expect(view.etaSeconds).toBeNull();
    });

    it('never exceeds 100% when bytes overshoot the file size', () => {
        expect(
            computeProgress({ ...base, bytes_processed: 1_200 }).percent,
        ).toBe(100);
    });
});

describe('formatDuration', () => {
    it.each([
        [0, '0s'],
        [59.4, '59s'],
        [65, '1m 05s'],
        [3_660, '1h 01m'],
    ])('formats %s seconds as %s', (seconds, expected) => {
        expect(formatDuration(seconds)).toBe(expected);
    });
});
