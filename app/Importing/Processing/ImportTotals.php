<?php

namespace App\Importing\Processing;

use App\Enums\ChunkStatus;
use App\Models\Import;

/**
 * Live totals for an import, summed from its chunks.
 *
 * Chunk rows are the source of truth while an import runs: each one is
 * written by a single job inside the same transaction as the rows it
 * counts, so the sums are exact even across retries. The import row only
 * receives the final figures when the import finishes.
 */
final readonly class ImportTotals
{
    public function __construct(
        public int $rowsProcessed,
        public int $rowsImported,
        public int $rowsFailed,
        public int $bytesProcessed,
        public ?int $peakMemoryBytes,
        public int $chunks,
        public int $failedChunks,
    ) {}

    public static function for(Import $import): self
    {
        $row = $import->chunks()->toBase()
            ->selectRaw('count(*) as chunks')
            ->selectRaw('coalesce(sum(rows_processed), 0) as rows_processed')
            ->selectRaw('coalesce(sum(rows_imported), 0) as rows_imported')
            ->selectRaw('coalesce(sum(rows_failed), 0) as rows_failed')
            ->selectRaw('coalesce(sum(bytes_processed), 0) as bytes_processed')
            ->selectRaw('max(peak_memory_bytes) as peak_memory_bytes')
            ->selectRaw('coalesce(sum(case when status = ? then 1 else 0 end), 0) as failed_chunks', [ChunkStatus::Failed->value])
            ->first();

        return new self(
            rowsProcessed: (int) $row->rows_processed,
            rowsImported: (int) $row->rows_imported,
            rowsFailed: (int) $row->rows_failed,
            bytesProcessed: (int) $row->bytes_processed,
            peakMemoryBytes: $row->peak_memory_bytes !== null ? (int) $row->peak_memory_bytes : null,
            chunks: (int) $row->chunks,
            failedChunks: (int) $row->failed_chunks,
        );
    }

    /**
     * @return array<string, int|null>
     */
    public function toImportAttributes(): array
    {
        return [
            'rows_processed' => $this->rowsProcessed,
            'rows_imported' => $this->rowsImported,
            'rows_failed' => $this->rowsFailed,
            'peak_memory_bytes' => $this->peakMemoryBytes,
        ];
    }
}
