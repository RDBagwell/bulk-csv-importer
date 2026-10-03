<?php

namespace App\Importing\Processing;

use App\Models\Import;
use Illuminate\Support\Facades\DB;

/**
 * Stores row errors for an import, up to a fixed cap.
 *
 * Parallel workers share the cap, so each write locks the import row,
 * reads how many errors are stored, inserts only what still fits and bumps
 * the counter, all in one short transaction. That keeps the cap exact
 * without a separate counter service. Errors beyond the cap are not
 * stored; the chunk's rows_failed counter still counts every bad row.
 *
 * Inserts are idempotent through the (import_id, line_number, column)
 * unique key, and the counter is increased by the rows actually inserted,
 * so a retried chunk does not inflate it.
 */
final class ErrorRecorder
{
    /** @var array<int, true> Imports known to be at the cap, to skip the lock entirely. */
    private array $full = [];

    public function __construct(private readonly int $cap) {}

    /**
     * @param  list<array<string, mixed>>  $errors  Rows for import_errors.
     */
    public function record(int $importId, array $errors): void
    {
        if ($errors === [] || isset($this->full[$importId])) {
            return;
        }

        DB::transaction(function () use ($importId, $errors) {
            $stored = (int) Import::query()->whereKey($importId)->lockForUpdate()->value('errors_stored');
            $room = $this->cap - $stored;

            if ($room <= 0) {
                $this->full[$importId] = true;

                return;
            }

            $inserted = 0;

            foreach (array_chunk(array_slice($errors, 0, $room), 500) as $slice) {
                $inserted += DB::table('import_errors')->insertOrIgnore($slice);
            }

            if ($inserted > 0) {
                Import::query()->whereKey($importId)->update(['errors_stored' => $stored + $inserted]);
            }

            if ($stored + $inserted >= $this->cap) {
                $this->full[$importId] = true;
            }
        });
    }
}
