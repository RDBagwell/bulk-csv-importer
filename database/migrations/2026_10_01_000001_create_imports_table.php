<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('definition', 64);
            $table->string('original_filename');
            $table->string('stored_path');
            $table->unsignedBigInteger('size_bytes');
            $table->string('status', 32)->default('pending');
            $table->string('mode', 16);
            $table->json('column_map');
            $table->unsignedSmallInteger('field_count');
            $table->json('options')->nullable();
            $table->unsignedBigInteger('total_rows')->nullable();
            $table->unsignedBigInteger('rows_processed')->default(0);
            $table->unsignedBigInteger('rows_imported')->default(0);
            $table->unsignedBigInteger('rows_failed')->default(0);
            $table->unsignedInteger('errors_stored')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedBigInteger('peak_memory_bytes')->nullable();
            $table->string('batch_id', 36)->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('file_deleted_at')->nullable();
            $table->timestamps();

            // "My imports, newest first": WHERE user_id = ? ORDER BY id DESC.
            $table->index(['user_id', 'id']);
            // Retention sweep: WHERE file_deleted_at IS NULL AND created_at < ?.
            $table->index(['file_deleted_at', 'created_at']);
        });

        Schema::create('import_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->unsignedBigInteger('start_offset');
            $table->unsignedBigInteger('end_offset');
            $table->unsignedBigInteger('first_record');
            $table->unsignedBigInteger('record_count')->nullable();
            // Resume point: every record before it is committed.
            $table->unsignedBigInteger('next_record');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedBigInteger('rows_processed')->default(0);
            $table->unsignedBigInteger('rows_imported')->default(0);
            $table->unsignedBigInteger('rows_failed')->default(0);
            $table->unsignedBigInteger('bytes_processed')->default(0);
            $table->unsignedBigInteger('peak_memory_bytes')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Loads all chunks of an import and guarantees one row per range.
            $table->unique(['import_id', 'sequence']);
        });

        Schema::create('import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('line_number');
            // '' for row-level problems such as a wrong field count; NULLs
            // would defeat the unique key below.
            $table->string('column', 64)->default('');
            $table->string('message');
            $table->string('raw_excerpt')->default('');
            $table->timestamp('created_at')->nullable();

            // Idempotency for retried chunks, and the report's ORDER BY line_number.
            $table->unique(['import_id', 'line_number', 'column']);
            // Live tail: WHERE import_id = ? ORDER BY id DESC LIMIT n.
            $table->index(['import_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_errors');
        Schema::dropIfExists('import_chunks');
        Schema::dropIfExists('imports');
    }
};
