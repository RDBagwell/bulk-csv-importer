<?php

namespace App\Jobs;

use App\Enums\ChunkStatus;
use App\Enums\ImportStatus;
use App\Importing\Processing\ChunkProcessor;
use App\Models\Import;
use App\Models\ImportChunk;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes one chunk of an import. The payload is the chunk id only: the
 * job reads its byte range from the stored file, never row data from the
 * queue.
 */
class ProcessImportChunk implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(public readonly int $chunkId)
    {
        $this->tries = (int) config('importer.queue.tries');
        $this->timeout = (int) config('importer.queue.timeout');
    }

    public function handle(ChunkProcessor $processor): void
    {
        $chunk = ImportChunk::query()->with('import')->find($this->chunkId);

        if ($chunk === null || $chunk->status === ChunkStatus::Completed) {
            return;
        }

        if ($this->batch()?->cancelled() || $chunk->import->status !== ImportStatus::Processing) {
            $chunk->forceFill(['status' => ChunkStatus::Cancelled])->save();

            return;
        }

        $importId = $chunk->import_id;

        $processor->process($chunk, fn (): bool => ! Import::query()
            ->whereKey($importId)
            ->where('status', ImportStatus::Processing)
            ->exists());
    }

    /**
     * Called once the job has exhausted its tries. The exception is already
     * reported by the queue worker; the chunk keeps only a generic message.
     */
    public function failed(?Throwable $exception): void
    {
        ImportChunk::query()->whereKey($this->chunkId)->update([
            'status' => ChunkStatus::Failed,
            'error' => 'Processing failed after '.$this->tries.' attempts.',
            'finished_at' => now(),
        ]);
    }
}
