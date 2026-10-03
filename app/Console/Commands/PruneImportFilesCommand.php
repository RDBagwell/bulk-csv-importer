<?php

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Models\Import;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes uploaded files of finished imports once they are older than the
 * retention period. The import record, its counters and its error report
 * are kept; only the raw upload goes. Imports still running are skipped
 * however old they are. Scheduled daily in routes/console.php.
 */
#[Signature('importer:prune-files {--days= : Retention in days (default: config importer.retention_days)}')]
#[Description('Delete uploaded CSV files of finished imports past the retention period')]
class PruneImportFilesCommand extends Command
{
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('importer.retention_days'));
        $disk = Storage::disk(config('importer.disk'));
        $deleted = 0;

        Import::query()
            ->whereNull('file_deleted_at')
            ->where('created_at', '<', now()->subDays($days))
            ->whereNotIn('status', array_map(
                fn (ImportStatus $status) => $status->value,
                array_filter(ImportStatus::cases(), fn (ImportStatus $status) => $status->isActive()),
            ))
            ->chunkById(200, function ($imports) use ($disk, &$deleted): void {
                /** @var Import $import */
                foreach ($imports as $import) {
                    $disk->delete($import->stored_path);
                    $import->forceFill(['file_deleted_at' => now()])->save();
                    $deleted++;
                }
            });

        $this->components->info("Deleted {$deleted} upload(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
