<?php

namespace App\Http\Resources;

use App\Enums\ImportStatus;
use App\Importing\ImportPipeline;
use App\Importing\Processing\ImportTotals;
use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The import as the UI sees it. Row and byte counts come from its chunks,
 * which are always current, except for completed imports, whose final
 * figures are on the import row. (A cancelled or failed import's row is
 * only updated once its last running chunk has stopped.)
 *
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Import $import */
        $import = $this->resource;
        $active = $import->status->isActive();
        $completed = in_array($import->status, [ImportStatus::Completed, ImportStatus::CompletedWithErrors], true);
        $totals = $completed ? null : ImportTotals::for($import);
        $bytesProcessed = $totals === null ? $import->size_bytes : min($totals->bytesProcessed, $import->size_bytes);

        $end = $import->finished_at ?? now();

        return [
            'id' => $import->id,
            'filename' => $import->original_filename,
            'status' => $import->status->value,
            'status_label' => $import->status->label(),
            'is_active' => $active,
            'mode' => $import->mode->value,
            'size_bytes' => $import->size_bytes,
            'bytes_processed' => $bytesProcessed,
            'total_rows' => $import->total_rows,
            'rows_processed' => $totals->rowsProcessed ?? $import->rows_processed,
            'rows_imported' => $totals->rowsImported ?? $import->rows_imported,
            'rows_failed' => $totals->rowsFailed ?? $import->rows_failed,
            'errors_stored' => $import->errors_stored,
            'max_stored_errors' => (int) config('importer.max_stored_errors'),
            'chunk_count' => $import->chunk_count,
            'peak_memory_bytes' => $totals->peakMemoryBytes ?? $import->peak_memory_bytes,
            'failure_reason' => $import->failure_reason,
            'created_at' => $import->created_at?->toIso8601String(),
            'started_at' => $import->started_at?->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
            'elapsed_seconds' => $import->started_at !== null
                ? round($import->started_at->diffInMilliseconds($end) / 1000, 1)
                : null,
            'owner' => $import->relationLoaded('user') ? ['name' => $import->user->name] : null,
            'can' => [
                'cancel' => $import->status->canTransitionTo(ImportStatus::Cancelled),
                'retry' => app(ImportPipeline::class)->canRetry($import),
                'download_errors' => $import->errors_stored > 0,
            ],
        ];
    }
}
