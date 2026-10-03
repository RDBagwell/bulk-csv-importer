export type ImportStatus =
    | 'pending'
    | 'validating'
    | 'processing'
    | 'completed'
    | 'completed_with_errors'
    | 'failed'
    | 'cancelled';

export type ImportSummary = {
    id: number;
    filename: string;
    status: ImportStatus;
    status_label: string;
    is_active: boolean;
    mode: 'sequential' | 'parallel';
    size_bytes: number;
    bytes_processed: number;
    total_rows: number | null;
    rows_processed: number;
    rows_imported: number;
    rows_failed: number;
    errors_stored: number;
    max_stored_errors: number;
    chunk_count: number;
    peak_memory_bytes: number | null;
    failure_reason: string | null;
    created_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    elapsed_seconds: number | null;
    owner: { name: string } | null;
    can: { cancel: boolean; retry: boolean; download_errors: boolean };
};

export type ImportErrorRow = {
    id: number;
    line_number: number;
    column: string | null;
    message: string;
    raw_excerpt: string;
};

export type ImportStatusResponse = {
    import: ImportSummary;
    latest_errors: ImportErrorRow[];
};

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};
