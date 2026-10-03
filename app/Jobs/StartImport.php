<?php

namespace App\Jobs;

use App\Importing\ImportPipeline;
use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Validating step of an import: re-checks the header, splits the file into
 * chunks and dispatches them. Carries only the import id.
 */
class StartImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly int $importId)
    {
        $this->timeout = (int) config('importer.queue.timeout');
    }

    public function handle(ImportPipeline $pipeline): void
    {
        $import = Import::query()->find($this->importId);

        if ($import !== null) {
            $pipeline->start($import);
        }
    }
}
