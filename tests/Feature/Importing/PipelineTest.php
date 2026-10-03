<?php

use App\Enums\ChunkStatus;
use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Exceptions\InvalidImportTransition;
use App\Importing\Csv\HeaderValidator;
use App\Importing\Definitions\TradeStatisticsDefinition;
use App\Importing\ImportPipeline;
use App\Importing\Processing\ChunkProcessor;
use App\Jobs\ProcessImportChunk;
use App\Models\Import;
use App\Models\ImportChunk;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const HEADER = "time_ref,account,code,country_code,product_type,value,status\n";

beforeEach(function () {
    Storage::fake('imports');
});

/**
 * Stores $rows valid rows (plus optional extra lines) and creates an import
 * for them, split into chunks but not yet processed.
 */
function preparedImport(int $rows, ImportMode $mode = ImportMode::Parallel, array $options = [], string $extra = ''): Import
{
    $csv = HEADER;

    for ($i = 1; $i <= $rows; $i++) {
        $csv .= sprintf("2024%02d,Exports,%02d,NZ,Goods,%d.50,F\n", $i % 12 + 1, $i % 99 + 1, $i);
    }

    Storage::disk('imports')->put('upload.csv', $csv.$extra);

    $definition = new TradeStatisticsDefinition;
    $mapping = app(HeaderValidator::class)->validate(Storage::disk('imports')->path('upload.csv'), $definition);

    return app(ImportPipeline::class)->create(User::factory()->create(), $definition, $mapping, 'upload.csv', 'upload.csv', $mode, $options);
}

/**
 * Runs the validating step with chunk jobs held back, so a test can drive them.
 */
function startWithoutRunningChunks(Import $import): Import
{
    Bus::fake([ProcessImportChunk::class]);
    app(ImportPipeline::class)->start($import);

    return $import->refresh();
}

function rowsInTable(Import $import): int
{
    return DB::table('trade_statistics')->where('import_id', $import->id)->count();
}

it('inserts no duplicates when the same chunk is processed twice', function () {
    $import = preparedImport(1_000, options: ['chunk_bytes' => 1_000_000, 'insert_batch_size' => 100]);
    startWithoutRunningChunks($import);
    $chunk = $import->chunks()->sole();

    app(ChunkProcessor::class)->process($chunk, fn () => false);

    // A redelivered job for an already-processed chunk, from the start.
    $chunk->refresh()->forceFill(['status' => ChunkStatus::Pending, 'next_record' => $chunk->first_record, 'rows_processed' => 0, 'rows_imported' => 0, 'rows_failed' => 0])->save();
    app(ChunkProcessor::class)->process($chunk->refresh(), fn () => false);

    expect(rowsInTable($import))->toBe(1_000)
        ->and($chunk->refresh()->rows_imported)->toBe(1_000);
});

it('resumes after a worker dies mid-chunk without duplicating rows or counts', function () {
    $import = preparedImport(1_000, options: ['chunk_bytes' => 1_000_000, 'insert_batch_size' => 100], extra: "202401,Bad,01,NZ,Goods,1,F\n");
    startWithoutRunningChunks($import);
    $chunk = $import->chunks()->sole();

    // Simulate the process being killed right after the third batch commits.
    $flushes = 0;
    expect(fn () => app(ChunkProcessor::class)->process($chunk, function () use (&$flushes) {
        if (++$flushes === 3) {
            throw new RuntimeException('worker killed');
        }

        return false;
    }))->toThrow(RuntimeException::class, 'worker killed');

    $chunk->refresh();
    expect($chunk->status)->toBe(ChunkStatus::Processing)
        ->and($chunk->rows_processed)->toBe(300)
        ->and($chunk->next_record)->toBe(302)
        ->and(rowsInTable($import))->toBe(300);

    // The queue redelivers the job; it resumes from the checkpoint.
    (new ProcessImportChunk($chunk->id))->handle(app(ChunkProcessor::class));

    $chunk->refresh();
    expect($chunk->status)->toBe(ChunkStatus::Completed)
        ->and($chunk->attempts)->toBe(2)
        ->and($chunk->rows_processed)->toBe(1_001)
        ->and($chunk->rows_imported)->toBe(1_000)
        ->and($chunk->rows_failed)->toBe(1)
        ->and(rowsInTable($import))->toBe(1_000)
        ->and($import->errors()->count())->toBe(1);
});

it('stores errors up to the cap and keeps counting beyond it', function () {
    config(['importer.max_stored_errors' => 25, 'importer.error_threshold_percent' => 100]);
    $bad = str_repeat("202401,Exports,01,NZ,Goods,oops,F\n", 60);
    $import = preparedImport(40, ImportMode::Parallel, ['chunk_bytes' => 400, 'insert_batch_size' => 7], $bad);

    app(ImportPipeline::class)->dispatch($import);
    $import->refresh();

    expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($import->rows_failed)->toBe(60)
        ->and($import->rows_imported)->toBe(40)
        ->and($import->errors_stored)->toBe(25)
        ->and($import->errors()->count())->toBe(25)
        ->and($import->chunk_count)->toBeGreaterThan(3);
});

it('records one error per failing column, with line, column, message and excerpt', function () {
    $import = preparedImport(2, ImportMode::Sequential, extra: "202413,Exprts,01,nz,Goods,1.5,F\n202401,Imports,01\n");

    app(ImportPipeline::class)->dispatch($import);

    $errors = $import->errors()->orderBy('line_number')->orderBy('column')->get(['line_number', 'column', 'message', 'raw_excerpt'])->toArray();

    expect($errors)->toBe([
        ['line_number' => 4, 'column' => 'account', 'message' => 'The account must be one of: Exports, Imports.', 'raw_excerpt' => '202413,Exprts,01,nz,Goods,1.5,F'],
        ['line_number' => 4, 'column' => 'country_code', 'message' => 'The country_code must be a 2-letter uppercase country code.', 'raw_excerpt' => '202413,Exprts,01,nz,Goods,1.5,F'],
        ['line_number' => 4, 'column' => 'time_ref', 'message' => 'The time_ref month must be between 01 and 12.', 'raw_excerpt' => '202413,Exprts,01,nz,Goods,1.5,F'],
        ['line_number' => 5, 'column' => '', 'message' => 'Expected 7 fields but found 3.', 'raw_excerpt' => '202401,Imports,01'],
    ])->and($import->refresh()->rows_failed)->toBe(2);
});

it('keeps going past bad rows but fails once the error threshold is crossed', function () {
    config(['importer.error_threshold_percent' => 10, 'importer.error_threshold_min_rows' => 50]);
    $bad = str_repeat("202401,Exports,01,NZ,Goods,x,F\n", 30);
    $import = preparedImport(100, ImportMode::Sequential, ['insert_batch_size' => 20], $bad);

    app(ImportPipeline::class)->dispatch($import);
    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Failed)
        // Stops at the first batch past the limit instead of reading on.
        ->and($import->failure_reason)->toBe('Too many invalid rows: 16.7% of the 120 rows read failed validation (limit 10%).')
        ->and($import->rows_processed)->toBe(120)
        ->and(app(ImportPipeline::class)->canRetry($import))->toBeFalse();
});

it('cancels: remaining chunks are skipped and a running chunk stops at its next batch', function () {
    $import = preparedImport(300, options: ['chunk_bytes' => 2_000, 'insert_batch_size' => 10]);
    startWithoutRunningChunks($import);
    $chunks = $import->chunks()->orderBy('sequence')->get();
    expect($chunks->count())->toBeGreaterThan(3);

    app(ChunkProcessor::class)->process($chunks[0], fn () => false);
    $stoppedAt = 0;

    $result = app(ChunkProcessor::class)->process($chunks[1], function () use ($import, &$stoppedAt) {
        if (++$stoppedAt === 2) {
            app(ImportPipeline::class)->cancel($import->refresh());
        }

        return ! Import::query()->whereKey($import->id)->where('status', ImportStatus::Processing)->exists();
    });

    expect($result)->toBe(ChunkStatus::Cancelled);

    (new ProcessImportChunk($chunks[2]->id))->handle(app(ChunkProcessor::class));

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Cancelled)
        ->and($import->finished_at)->not->toBeNull()
        ->and($chunks[1]->refresh()->rows_processed)->toBe(20)
        ->and($chunks[2]->refresh()->status)->toBe(ChunkStatus::Cancelled)
        ->and($import->chunks()->where('status', ChunkStatus::Completed)->count())->toBe(1)
        ->and(app(ImportPipeline::class)->cancel($import))->toBeFalse();
});

it('retries only the chunks that failed', function () {
    $import = preparedImport(300, options: ['chunk_bytes' => 2_000, 'insert_batch_size' => 25]);
    startWithoutRunningChunks($import);
    $chunks = $import->chunks()->orderBy('sequence')->get();

    foreach ($chunks as $i => $chunk) {
        if ($i === 1) {
            // Fails after its first batch, then exhausts its tries.
            try {
                app(ChunkProcessor::class)->process($chunk, fn () => throw new RuntimeException('database went away'));
            } catch (RuntimeException) {
                (new ProcessImportChunk($chunk->id))->failed(new RuntimeException('database went away'));
            }
        } else {
            app(ChunkProcessor::class)->process($chunk, fn () => false);
        }
    }

    app(ImportPipeline::class)->finalize($import->id);
    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->failure_reason)->toBe("1 of {$chunks->count()} chunks could not be processed. Retry to re-run only those chunks.")
        ->and(app(ImportPipeline::class)->canRetry($import))->toBeTrue();

    $completedAt = $chunks[0]->refresh()->finished_at;
    $attemptsBefore = $import->chunks()->pluck('attempts', 'sequence');

    // Stop faking so the retry batch really runs (sync queue in tests).
    Bus::swap(Bus::getFacadeRoot()->dispatcher);
    app(ImportPipeline::class)->retryFailedChunks($import);
    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->failure_reason)->toBeNull()
        ->and($import->rows_imported)->toBe(300)
        ->and(rowsInTable($import))->toBe(300)
        ->and($chunks[0]->refresh()->finished_at->equalTo($completedAt))->toBeTrue()
        ->and($import->chunks()->pluck('attempts', 'sequence')->all())
        ->toBe($attemptsBefore->map(fn ($n, $seq) => $seq === 1 ? $n + 1 : $n)->all());
});

it('refuses transitions that are not in the table', function () {
    $import = Import::factory()->create(['status' => ImportStatus::Completed]);

    expect(fn () => $import->transitionTo(ImportStatus::Processing))->toThrow(InvalidImportTransition::class);
});

it('does not let two actors win the same transition', function () {
    $import = Import::factory()->create(['status' => ImportStatus::Processing]);
    $stale = Import::query()->find($import->id);

    expect($import->transitionTo(ImportStatus::Cancelled))->toBeTrue()
        ->and($stale->transitionTo(ImportStatus::Completed))->toBeFalse()
        ->and($stale->status)->toBe(ImportStatus::Cancelled);
});

it('never puts row data on the queue', function () {
    Queue::fake();
    $import = preparedImport(50, options: ['chunk_bytes' => 300]);

    app(ImportPipeline::class)->start($import);

    Queue::assertPushed(ProcessImportChunk::class, function (ProcessImportChunk $job) {
        $payload = serialize($job);

        return ! str_contains($payload, 'Exports') && ImportChunk::query()->whereKey($job->chunkId)->exists();
    });
});
