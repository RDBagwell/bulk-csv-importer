<?php

namespace App\Importing;

use App\Enums\ChunkStatus;
use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Importing\Csv\ByteRange;
use App\Importing\Csv\HeaderMapping;
use App\Importing\Csv\HeaderValidator;
use App\Importing\Csv\RecordBoundaryScanner;
use App\Importing\Definitions\DefinitionRegistry;
use App\Importing\Definitions\ImportDefinition;
use App\Importing\Processing\ErrorThreshold;
use App\Importing\Processing\ImportTotals;
use App\Jobs\ProcessImportChunk;
use App\Jobs\StartImport;
use App\Models\Import;
use App\Models\ImportChunk;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

/**
 * Orchestrates an import from upload to final status:
 *
 *   create()   pending      record the upload (header already validated)
 *   start()    validating   re-check the header, split the file into chunks
 *              processing   dispatch the chunks as one job batch
 *   finalize() completed | completed_with_errors | failed
 *
 * plus cancel() and retryFailedChunks(). Status changes always go through
 * Import::transitionTo().
 */
final class ImportPipeline
{
    public function __construct(
        private readonly DefinitionRegistry $definitions,
        private readonly HeaderValidator $headers,
        private readonly ErrorThreshold $threshold,
    ) {}

    /**
     * @param  array<string, int>  $options  Per-import overrides (insert_batch_size, chunk_bytes), used by the benchmark.
     */
    public function create(
        User $user,
        ImportDefinition $definition,
        HeaderMapping $mapping,
        string $storedPath,
        string $originalFilename,
        ?ImportMode $mode = null,
        array $options = [],
    ): Import {
        $size = (int) Storage::disk(config('importer.disk'))->size($storedPath);

        /** @var Import $import */
        $import = $user->imports()->create([
            'definition' => $definition->key(),
            'original_filename' => $originalFilename,
            'stored_path' => $storedPath,
            'size_bytes' => $size,
            'mode' => $mode ?? $this->chooseMode($size),
            'column_map' => $mapping->positions,
            'field_count' => $mapping->fieldCount,
            'options' => $options ?: null,
        ]);

        return $import;
    }

    public function dispatch(Import $import): void
    {
        StartImport::dispatch($import->id)
            ->onConnection($this->queueConnection())
            ->onQueue(config('importer.queue.name'));
    }

    public function chooseMode(int $bytes): ImportMode
    {
        return match (config('importer.mode')) {
            'sequential' => ImportMode::Sequential,
            'parallel' => ImportMode::Parallel,
            default => $bytes >= (int) config('importer.parallel_min_bytes') ? ImportMode::Parallel : ImportMode::Sequential,
        };
    }

    /**
     * Validating step: re-checks the header, plans the chunks and hands them
     * to the queue. Runs in a job so a multi-gigabyte scan never blocks a request.
     */
    public function start(Import $import): void
    {
        if ($import->status !== ImportStatus::Pending || ! $import->transitionTo(ImportStatus::Validating, ['started_at' => now()])) {
            return;
        }

        try {
            $path = Storage::disk(config('importer.disk'))->path($import->stored_path);
            $mapping = $this->headers->validate($path, $this->definitions->get($import->definition));

            if (! $mapping->isValid() || ! $this->sameMapping($mapping->positions, $import->column_map)) {
                $this->fail($import, 'The file changed after upload or its header is no longer valid.');

                return;
            }

            [$ranges, $totalRows] = $this->plan($import, $path);
            $chunks = $this->createChunks($import, $ranges, $totalRows);

            if (! $import->transitionTo(ImportStatus::Processing)) {
                // Cancelled while we were scanning.
                $import->chunks()->update(['status' => ChunkStatus::Cancelled]);

                return;
            }

            $this->dispatchChunks($import, $chunks);
        } catch (Throwable $e) {
            report($e);
            $this->fail($import, 'The file could not be prepared for import.');
        }
    }

    /**
     * @return array{0: list<ByteRange>, 1: int|null}
     */
    private function plan(Import $import, string $path): array
    {
        if ($import->mode === ImportMode::Sequential) {
            // One range, no pre-scan: the row total becomes known when the
            // single job finishes; until then the UI estimates it from bytes.
            return [[new ByteRange(0, $import->size_bytes, 1)], null];
        }

        $chunkBytes = $import->option('chunk_bytes', (int) config('importer.chunk_bytes'));
        $split = (new RecordBoundaryScanner)->split($path, $chunkBytes);

        return [$split->ranges, max(0, $split->records - 1)];
    }

    /**
     * @param  list<ByteRange>  $ranges
     * @return Collection<int, ImportChunk>
     */
    private function createChunks(Import $import, array $ranges, ?int $totalRows): Collection
    {
        return DB::transaction(function () use ($import, $ranges, $totalRows) {
            $chunks = collect($ranges)->values()->map(fn (ByteRange $range, int $sequence) => $import->chunks()->create([
                'sequence' => $sequence,
                'start_offset' => $range->start,
                'end_offset' => $range->end,
                'first_record' => $range->firstRecord,
                'record_count' => $range->records,
                'next_record' => $range->firstRecord,
            ]));

            $import->forceFill(['chunk_count' => $chunks->count(), 'total_rows' => $totalRows])->save();

            return $chunks;
        });
    }

    /**
     * @param  Collection<int, ImportChunk>  $chunks
     */
    private function dispatchChunks(Import $import, Collection $chunks): void
    {
        $importId = $import->id;

        $batch = Bus::batch($chunks->map(fn (ImportChunk $chunk) => new ProcessImportChunk($chunk->id))->all())
            ->name("import:{$importId}")
            ->allowFailures()
            ->onConnection($this->queueConnection())
            ->onQueue(config('importer.queue.name'))
            ->finally(static function (Batch $batch) use ($importId): void {
                app(ImportPipeline::class)->finalize($importId);
            })
            ->dispatch();

        // With a sync queue the batch has already finished at this point,
        // which is fine: nothing reads batch_id except cancel().
        Import::query()->whereKey($importId)->update(['batch_id' => $batch->id]);
        $import->batch_id = $batch->id;
    }

    /**
     * Runs once every chunk job in the batch has finished or failed.
     */
    public function finalize(int $importId): void
    {
        $import = Import::query()->find($importId);

        if ($import === null) {
            return;
        }

        $totals = ImportTotals::for($import);
        $attributes = [
            ...$totals->toImportAttributes(),
            'total_rows' => $totals->rowsProcessed,
            'finished_at' => now(),
        ];

        if ($import->status !== ImportStatus::Processing) {
            // Cancelled or failed while running: record what was done.
            Import::query()->whereKey($importId)->update([
                ...$totals->toImportAttributes(),
                'finished_at' => $import->finished_at ?? now(),
            ]);

            return;
        }

        [$status, $reason] = match (true) {
            $totals->failedChunks > 0 => [ImportStatus::Failed, sprintf(
                '%d of %d chunks could not be processed. Retry to re-run only those chunks.',
                $totals->failedChunks,
                $totals->chunks,
            )],
            $this->threshold->isExceeded($totals->rowsProcessed, $totals->rowsFailed) => [
                ImportStatus::Failed, $this->threshold->describe($totals->rowsProcessed, $totals->rowsFailed),
            ],
            $totals->rowsFailed > 0 => [ImportStatus::CompletedWithErrors, null],
            default => [ImportStatus::Completed, null],
        };

        $import->transitionTo($status, [...$attributes, 'failure_reason' => $reason]);
    }

    public function cancel(Import $import): bool
    {
        if (! $import->status->canTransitionTo(ImportStatus::Cancelled)) {
            return false;
        }

        if (! $import->transitionTo(ImportStatus::Cancelled, ['finished_at' => now()])) {
            return false;
        }

        if ($import->batch_id !== null) {
            Bus::findBatch($import->batch_id)?->cancel();
        }

        // Chunks already running notice the status at their next flush.
        $import->chunks()->where('status', ChunkStatus::Pending)->update(['status' => ChunkStatus::Cancelled]);

        return true;
    }

    public function canRetry(Import $import): bool
    {
        return $import->status === ImportStatus::Failed
            && $import->file_deleted_at === null
            && $import->chunks()->where('status', ChunkStatus::Failed)->exists();
    }

    /**
     * Re-runs only the chunks that failed. Each resumes from its last
     * committed batch, and the idempotent insert covers any overlap.
     */
    public function retryFailedChunks(Import $import): void
    {
        if (! $this->canRetry($import)) {
            throw new LogicException('Only failed imports with failed chunks can be retried.');
        }

        $chunks = DB::transaction(function () use ($import) {
            $chunks = $import->chunks()->where('status', ChunkStatus::Failed)->lockForUpdate()->get();

            $import->chunks()->whereKey($chunks->modelKeys())->update(['status' => ChunkStatus::Pending, 'error' => null]);

            $import->transitionTo(ImportStatus::Processing, ['failure_reason' => null, 'finished_at' => null]);

            return $chunks;
        });

        $this->dispatchChunks($import, $chunks);
    }

    /**
     * MySQL's JSON type does not keep object key order, so compare the
     * stored map key by key.
     *
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     */
    private function sameMapping(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return $a === $b;
    }

    private function queueConnection(): string
    {
        return config('importer.queue.connection') ?? config('queue.default');
    }

    private function fail(Import $import, string $reason): void
    {
        if ($import->status->canTransitionTo(ImportStatus::Failed)) {
            $import->transitionTo(ImportStatus::Failed, ['failure_reason' => $reason, 'finished_at' => now()]);
        }
    }
}
