<?php

use App\Enums\ChunkStatus;
use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Importing\Csv\HeaderValidator;
use App\Importing\Definitions\TradeStatisticsDefinition;
use App\Importing\Generator\TradeCsvGenerator;
use App\Importing\ImportPipeline;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('imports');
    config(['importer.error_threshold_percent' => 5]);
});

function importGeneratedFile(int $rows, float $errors, ImportMode $mode, array $options = [], bool $quotedNewlines = false): array
{
    $disk = Storage::disk('imports');
    $result = (new TradeCsvGenerator(seed: 7))->generate($disk->path('generated.csv'), $rows, $errors, $quotedNewlines);
    $definition = new TradeStatisticsDefinition;
    $mapping = app(HeaderValidator::class)->validate($disk->path('generated.csv'), $definition);

    $pipeline = app(ImportPipeline::class);
    $import = $pipeline->create(User::factory()->create(), $definition, $mapping, 'generated.csv', 'trade.csv', $mode, $options);
    $pipeline->dispatch($import);

    return [$import->refresh(), $result];
}

it('imports a generated 10k-row file with 1% bad rows, with exact counts', function (ImportMode $mode) {
    [$import, $generated] = importGeneratedFile(10_000, 1, $mode, ['chunk_bytes' => 64 * 1024, 'insert_batch_size' => 700]);

    expect($generated->invalidRows)->toBe(100)
        ->and($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($import->rows_processed)->toBe(10_000)
        ->and($import->rows_imported)->toBe(9_900)
        ->and($import->rows_failed)->toBe(100)
        ->and($import->total_rows)->toBe(10_000)
        ->and($import->errors_stored)->toBe(100)
        ->and($import->finished_at)->not->toBeNull()
        ->and(DB::table('trade_statistics')->where('import_id', $import->id)->count())->toBe(9_900)
        ->and($import->errors()->count())->toBe(100)
        ->and($import->chunks()->where('status', '!=', ChunkStatus::Completed)->count())->toBe(0);

    if ($mode === ImportMode::Parallel) {
        expect($import->chunk_count)->toBeGreaterThan(1);
    }
})->with([ImportMode::Sequential, ImportMode::Parallel]);

it('stores decimal values exactly', function () {
    [$import] = importGeneratedFile(50, 0, ImportMode::Sequential);

    $row = DB::table('trade_statistics')->where('import_id', $import->id)->orderBy('line_number')->first();
    $line = explode(',', file(Storage::disk('imports')->path('generated.csv'))[1]);

    expect((string) $row->value)->toBe(trim($line[5]));
});

it('splits files with quoted newlines correctly and reports them by record number', function () {
    [$import, $generated] = importGeneratedFile(3_000, 0, ImportMode::Parallel, ['chunk_bytes' => 4 * 1024], quotedNewlines: true);

    expect($import->chunk_count)->toBeGreaterThan(10)
        ->and($import->rows_processed)->toBe(3_000)
        ->and($import->rows_failed)->toBe($generated->invalidRows)
        ->and($import->rows_imported)->toBe($generated->validRows())
        ->and($import->errors()->pluck('column')->unique()->all())->toBe(['code']);
});

it('fails the import when the error threshold is exceeded', function () {
    [$import] = importGeneratedFile(5_000, 20, ImportMode::Sequential, ['insert_batch_size' => 500]);

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->failure_reason)->toContain('Too many invalid rows')
        ->and($import->rows_processed)->toBeLessThan(5_000);
});
