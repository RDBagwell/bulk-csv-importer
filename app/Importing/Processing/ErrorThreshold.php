<?php

namespace App\Importing\Processing;

use App\Enums\ImportStatus;
use App\Models\Import;
use Illuminate\Support\Facades\Bus;

/**
 * Fails an import once too large a share of its rows is invalid.
 *
 * The check only kicks in after a minimum number of rows, so a few bad
 * rows at the top of a file do not abort it, and it runs after every
 * committed batch, so a hopeless file stops early instead of being read
 * to the end.
 */
final readonly class ErrorThreshold
{
    public function __construct(
        private float $percent,
        private int $minimumRows,
    ) {}

    public function isExceeded(int $processed, int $failed): bool
    {
        return $processed >= $this->minimumRows && $failed * 100 > $this->percent * $processed;
    }

    public function describe(int $processed, int $failed): string
    {
        return sprintf(
            'Too many invalid rows: %s%% of the %s rows read failed validation (limit %s%%).',
            number_format($processed > 0 ? $failed * 100 / $processed : 0, 1),
            number_format($processed),
            rtrim(rtrim(number_format($this->percent, 2), '0'), '.'),
        );
    }

    /**
     * Returns true if the import was (or already is) stopped because of the threshold.
     */
    public function abortIfExceeded(Import $import): bool
    {
        $totals = ImportTotals::for($import);

        if (! $this->isExceeded($totals->rowsProcessed, $totals->rowsFailed)) {
            return false;
        }

        $import->refresh();

        if ($import->status !== ImportStatus::Processing) {
            return true;
        }

        $failed = $import->transitionTo(ImportStatus::Failed, [
            ...$totals->toImportAttributes(),
            'failure_reason' => $this->describe($totals->rowsProcessed, $totals->rowsFailed),
            'finished_at' => now(),
        ]);

        if ($failed && $import->batch_id !== null) {
            Bus::findBatch($import->batch_id)?->cancel();
        }

        return true;
    }
}
