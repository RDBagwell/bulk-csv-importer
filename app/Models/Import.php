<?php

namespace App\Models;

use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Exceptions\InvalidImportTransition;
use Carbon\CarbonImmutable;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $definition
 * @property string $original_filename
 * @property string $stored_path
 * @property int $size_bytes
 * @property ImportStatus $status
 * @property ImportMode $mode
 * @property array<string, int> $column_map
 * @property int $field_count
 * @property array<string, int>|null $options
 * @property int|null $total_rows
 * @property int $rows_processed
 * @property int $rows_imported
 * @property int $rows_failed
 * @property int $errors_stored
 * @property int $chunk_count
 * @property int|null $peak_memory_bytes
 * @property string|null $batch_id
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $file_deleted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'definition', 'original_filename', 'stored_path', 'size_bytes', 'mode', 'column_map', 'field_count', 'options',
])]
class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'mode' => ImportMode::class,
            'column_map' => 'array',
            'options' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'file_deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ImportChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(ImportChunk::class);
    }

    /**
     * @return HasMany<ImportError, $this>
     */
    public function errors(): HasMany
    {
        return $this->hasMany(ImportError::class);
    }

    /**
     * Moves the import to a new status, the only way status ever changes.
     *
     * The update is conditional on the current status being one that may
     * move to $to, so concurrent actors (a worker finishing, a user
     * cancelling) cannot both win. Returns false if another actor changed
     * the status first; throws if the transition is never allowed from the
     * status this model last saw.
     *
     * @param  array<string, mixed>  $attributes  Extra columns to write in the same update.
     */
    public function transitionTo(ImportStatus $to, array $attributes = []): bool
    {
        if (! $this->status->canTransitionTo($to)) {
            throw InvalidImportTransition::between($this->status, $to);
        }

        $updated = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', ImportStatus::sourcesOf($to))
            ->update([...$attributes, 'status' => $to->value, 'updated_at' => now()]);

        $this->refresh();

        return $updated === 1;
    }

    public function option(string $key, int $default): int
    {
        return (int) ($this->options[$key] ?? $default);
    }
}
