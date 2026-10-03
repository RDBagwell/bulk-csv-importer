<?php

namespace App\Importing\Processing;

use App\Enums\ChunkStatus;
use App\Importing\Csv\RangeReader;
use App\Importing\Definitions\Column;
use App\Importing\Definitions\DefinitionRegistry;
use App\Importing\Rules\Invalid;
use App\Models\Import;
use App\Models\ImportChunk;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Streams one chunk (byte range) of an import: validates each record,
 * buffers valid rows and writes them in batches.
 *
 * Every flush commits, in one transaction, the batch of rows plus the
 * chunk's counters and its resume point (next_record). So if the worker
 * dies, the retried job resumes after the last committed batch, and the
 * counters are exact. Rows are written with an upsert keyed on
 * (import_id, line_number), so even replaying a batch cannot duplicate.
 *
 * Memory is bounded by one batch of rows plus the parser's read buffer.
 */
final class ChunkProcessor
{
    /** Bound parameters per statement: MySQL and PostgreSQL allow 65,535, SQLite 32,766. */
    private const array PLACEHOLDER_LIMITS = ['sqlite' => 32_766];

    private const int DEFAULT_PLACEHOLDER_LIMIT = 65_535;

    public function __construct(
        private readonly DefinitionRegistry $definitions,
        private readonly ErrorRecorder $errors,
        private readonly ErrorThreshold $threshold,
    ) {}

    /**
     * @param  Closure(): bool  $shouldStop  Checked after every flush; true stops the chunk early (cancel, abort).
     */
    public function process(ImportChunk $chunk, Closure $shouldStop): ChunkStatus
    {
        $import = $chunk->loadMissing('import')->import;
        $definition = $this->definitions->get($import->definition);
        $table = $definition->table();
        $batchSize = max(1, $import->option('insert_batch_size', (int) config('importer.insert_batch_size')));
        $excerptLength = (int) config('importer.raw_excerpt_length');
        $expectedFields = $import->field_count;

        /** @var list<array{0: Column, 1: int}> $columns */
        $columns = [];

        foreach ($definition->columns() as $column) {
            if (isset($import->column_map[$column->attribute])) {
                $columns[] = [$column, $import->column_map[$column->attribute]];
            }
        }

        memory_reset_peak_usage();
        $this->markStarted($chunk);

        $reader = new RangeReader(Storage::disk(config('importer.disk'))->path($import->stored_path), $chunk->range());
        $rows = [];
        $errors = [];
        $pending = 0;
        $processed = $chunk->rows_processed;
        $imported = $chunk->rows_imported;
        $failed = $chunk->rows_failed;
        $lastRecord = $chunk->next_record - 1;
        $now = now();

        foreach ($reader->records($chunk->next_record) as $number => $fields) {
            $lastRecord = $number;

            // The header is record 1; blank lines are not rows.
            if ($number === 1 || $fields === [] || $fields === [null]) {
                continue;
            }

            $processed++;
            $pending++;
            $fieldCount = count($fields);

            if ($fieldCount !== $expectedFields) {
                $errors[] = $this->error($import->id, $number, '', "Expected {$expectedFields} fields but found {$fieldCount}.", $fields, $excerptLength, $now);
                $failed++;
            } else {
                $row = ['import_id' => $import->id, 'line_number' => $number];
                $valid = true;

                foreach ($columns as [$column, $position]) {
                    $value = $column->normalize($fields[$position]);

                    if ($value instanceof Invalid) {
                        $errors[] = $this->error($import->id, $number, $column->header, "The {$column->header} {$value->message}.", $fields, $excerptLength, $now);
                        $valid = false;
                    } else {
                        $row[$column->attribute] = $value;
                    }
                }

                if ($valid) {
                    $rows[] = $row;
                    $imported++;
                } else {
                    $failed++;
                }
            }

            if ($pending >= $batchSize) {
                $this->flush($chunk, $table, $rows, $errors, $processed, $imported, $failed, $number + 1, $reader->bytesRead());
                $rows = $errors = [];
                $pending = 0;
                $now = now();

                if ($shouldStop() || $this->threshold->abortIfExceeded($import)) {
                    $this->markStopped($chunk);

                    return ChunkStatus::Cancelled;
                }
            }
        }

        $this->flush($chunk, $table, $rows, $errors, $processed, $imported, $failed, $lastRecord + 1, $chunk->end_offset - $chunk->start_offset);
        $this->markCompleted($chunk);
        $this->threshold->abortIfExceeded($import);

        return ChunkStatus::Completed;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $errors
     */
    private function flush(
        ImportChunk $chunk,
        string $table,
        array $rows,
        array $errors,
        int $processed,
        int $imported,
        int $failed,
        int $nextRecord,
        int $bytesProcessed,
    ): void {
        // Errors first, in their own short transaction (it locks the import
        // row). If the rows below then fail, the retry re-inserts the same
        // errors, which the unique key ignores.
        $this->errors->record($chunk->import_id, $errors);

        try {
            DB::transaction(function () use ($chunk, $table, $rows, $processed, $imported, $failed, $nextRecord, $bytesProcessed) {
                if ($rows !== []) {
                    $perStatement = max(1, intdiv($this->placeholderLimit(), count($rows[0])));

                    foreach (array_chunk($rows, $perStatement) as $slice) {
                        // ON DUPLICATE KEY UPDATE with a no-op assignment rather
                        // than INSERT IGNORE: IGNORE would also turn genuine data
                        // errors (truncation, bad values) into silent warnings.
                        DB::table($table)->upsert($slice, ['import_id', 'line_number'], ['line_number']);
                    }
                }

                $chunk->forceFill([
                    'rows_processed' => $processed,
                    'rows_imported' => $imported,
                    'rows_failed' => $failed,
                    'next_record' => $nextRecord,
                    'bytes_processed' => $bytesProcessed,
                ])->save();
            });
        } catch (QueryException $e) {
            throw ImportWriteFailed::from($e, $table);
        }
    }

    /**
     * @param  list<string|null>  $fields
     * @return array<string, mixed>
     */
    private function error(int $importId, int $line, string $column, string $message, array $fields, int $excerptLength, mixed $now): array
    {
        return [
            'import_id' => $importId,
            'line_number' => $line,
            'column' => $column,
            'message' => mb_strimwidth($message, 0, 255, '…'),
            'raw_excerpt' => $this->excerpt($fields, $excerptLength),
            'created_at' => $now,
        ];
    }

    /**
     * Rebuilds a readable, length-limited copy of the record. The parser has
     * already consumed the raw bytes, so fields are re-joined with minimal
     * quoting; invalid UTF-8 is replaced so the excerpt can always be stored.
     *
     * @param  list<string|null>  $fields
     */
    private function excerpt(array $fields, int $length): string
    {
        $parts = [];
        $budget = $length * 4;

        foreach ($fields as $field) {
            $field = (string) $field;

            if (strpbrk($field, ",\"\r\n") !== false) {
                $field = '"'.str_replace('"', '""', $field).'"';
            }

            $parts[] = $field;
            $budget -= strlen($field) + 1;

            if ($budget <= 0) {
                break;
            }
        }

        return mb_strimwidth(mb_scrub(implode(',', $parts), 'UTF-8'), 0, $length, '…', 'UTF-8');
    }

    private function placeholderLimit(): int
    {
        return self::PLACEHOLDER_LIMITS[DB::connection()->getDriverName()] ?? self::DEFAULT_PLACEHOLDER_LIMIT;
    }

    private function markStarted(ImportChunk $chunk): void
    {
        $chunk->forceFill([
            'status' => ChunkStatus::Processing,
            'attempts' => $chunk->attempts + 1,
            'started_at' => $chunk->started_at ?? now(),
            'error' => null,
        ])->save();
    }

    private function markCompleted(ImportChunk $chunk): void
    {
        $chunk->forceFill([
            'status' => ChunkStatus::Completed,
            'finished_at' => now(),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ])->save();
    }

    private function markStopped(ImportChunk $chunk): void
    {
        $chunk->forceFill([
            'status' => ChunkStatus::Cancelled,
            'finished_at' => now(),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ])->save();
    }
}
