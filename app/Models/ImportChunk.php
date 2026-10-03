<?php

namespace App\Models;

use App\Enums\ChunkStatus;
use App\Importing\Csv\ByteRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One byte range of an import's file, processed by one job.
 *
 * @property int $id
 * @property int $import_id
 * @property int $sequence
 * @property int $start_offset
 * @property int $end_offset
 * @property int $first_record
 * @property int|null $record_count
 * @property int $next_record
 * @property ChunkStatus $status
 * @property int $attempts
 * @property int $rows_processed
 * @property int $rows_imported
 * @property int $rows_failed
 * @property int $bytes_processed
 * @property int|null $peak_memory_bytes
 * @property string|null $error
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property-read Import $import
 */
#[Fillable([
    'sequence', 'start_offset', 'end_offset', 'first_record', 'record_count', 'next_record', 'status',
])]
class ImportChunk extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => ChunkStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Import, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function range(): ByteRange
    {
        return new ByteRange($this->start_offset, $this->end_offset, $this->first_record, $this->record_count);
    }
}
