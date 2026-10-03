<?php

use App\Importing\Definitions\TradeStatisticsDefinition;

return [

    /*
    |--------------------------------------------------------------------------
    | Dataset definitions
    |--------------------------------------------------------------------------
    |
    | Each definition describes one importable dataset: its CSV columns, the
    | validation rules and the table rows are written to. Adding a dataset
    | means writing another definition class and registering it here; the
    | pipeline itself does not change.
    |
    */

    'definitions' => [
        'trade_statistics' => TradeStatisticsDefinition::class,
    ],

    'default_definition' => 'trade_statistics',

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | Uploaded files are moved to this local disk under a random name. The
    | disk must be local (the pipeline seeks within the file) and shared by
    | the web and worker containers. Keep max_upload_kb in line with PHP's
    | upload_max_filesize / post_max_size and nginx's client_max_body_size
    | (see docker/ and README "Large uploads").
    |
    */

    'disk' => env('IMPORTER_DISK', 'imports'),

    'max_upload_kb' => (int) env('IMPORTER_MAX_UPLOAD_KB', 1024 * 1024),

    'uploads_per_minute' => (int) env('IMPORTER_UPLOADS_PER_MINUTE', 10),

    'retention_days' => (int) env('IMPORTER_RETENTION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Processing
    |--------------------------------------------------------------------------
    |
    | mode: "sequential" streams the whole file in one job, "parallel" splits
    | it into byte ranges on record boundaries and processes them as a job
    | batch, "auto" picks parallel for files at or above parallel_min_bytes.
    | The defaults come from docs/BENCHMARKS.md.
    |
    */

    'mode' => env('IMPORTER_MODE', 'auto'),

    'parallel_min_bytes' => (int) env('IMPORTER_PARALLEL_MIN_BYTES', 16 * 1024 * 1024),

    'chunk_bytes' => (int) env('IMPORTER_CHUNK_BYTES', 8 * 1024 * 1024),

    'insert_batch_size' => (int) env('IMPORTER_INSERT_BATCH_SIZE', 2000),

    'queue' => [
        'connection' => env('IMPORTER_QUEUE_CONNECTION'),
        'name' => env('IMPORTER_QUEUE', 'imports'),
        'tries' => (int) env('IMPORTER_JOB_TRIES', 3),
        'timeout' => (int) env('IMPORTER_JOB_TIMEOUT', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Errors
    |--------------------------------------------------------------------------
    |
    | Invalid rows never stop an import. Up to max_stored_errors are kept for
    | the report; beyond that they are only counted. If more than
    | error_threshold_percent of rows fail (checked once at least
    | error_threshold_min_rows have been read) the import is marked failed.
    |
    */

    'max_stored_errors' => (int) env('IMPORTER_MAX_STORED_ERRORS', 10_000),

    'error_threshold_percent' => (float) env('IMPORTER_ERROR_THRESHOLD_PERCENT', 5),

    'error_threshold_min_rows' => (int) env('IMPORTER_ERROR_THRESHOLD_MIN_ROWS', 1_000),

    'raw_excerpt_length' => 200,

    // E-mail addresses allowed to open /horizon outside the local environment.
    'horizon_admins' => env('HORIZON_ADMINS', ''),

];
