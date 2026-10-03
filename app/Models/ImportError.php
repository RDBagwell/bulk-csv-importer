<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invalid row (or cell) found while importing. Written in bulk by
 * ErrorRecorder, never mass-assigned from user input, so no attributes are
 * fillable.
 *
 * @property int $id
 * @property int $import_id
 * @property int $line_number
 * @property string $column
 * @property string $message
 * @property string $raw_excerpt
 * @property CarbonImmutable|null $created_at
 */
class ImportError extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Import, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
