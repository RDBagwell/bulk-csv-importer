import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { ImportProgress } from '@/components/imports/import-progress';

const summary = {
    status: 'processing' as const,
    is_active: true,
    size_bytes: 4_000,
    bytes_processed: 1_000,
    total_rows: null,
    rows_processed: 25_000,
    rows_failed: 12,
    elapsed_seconds: 5,
};

describe('<ImportProgress>', () => {
    it('renders an accessible progress bar with the current percentage', () => {
        const html = renderToStaticMarkup(<ImportProgress import={summary} />);

        expect(html).toContain('role="progressbar"');
        expect(html).toContain('aria-valuenow="25"');
        expect(html).toContain('width:25%');
    });

    it('marks an estimated total and shows rate and time left', () => {
        const html = renderToStaticMarkup(<ImportProgress import={summary} />);

        expect(html).toContain('25,000 of ~100,000');
        expect(html).toContain('5,000');
        expect(html).toContain('Time left');
        expect(html).toContain('~15s');
    });

    it('shows the duration instead of time left once finished', () => {
        const html = renderToStaticMarkup(
            <ImportProgress
                import={{
                    ...summary,
                    status: 'completed',
                    is_active: false,
                    bytes_processed: 4_000,
                    total_rows: 100_000,
                    rows_processed: 100_000,
                    elapsed_seconds: 125,
                }}
            />,
        );

        expect(html).toContain('aria-valuenow="100"');
        expect(html).toContain('100,000 of 100,000');
        expect(html).toContain('Duration');
        expect(html).toContain('2m 05s');
        expect(html).not.toContain('~');
    });
});
