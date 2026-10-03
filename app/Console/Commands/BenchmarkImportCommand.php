<?php

namespace App\Console\Commands;

use App\Enums\ImportMode;
use App\Importing\Csv\HeaderValidator;
use App\Importing\Definitions\DefinitionRegistry;
use App\Importing\ImportPipeline;
use App\Models\Import;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Runs one import end to end and reports wall time, throughput and memory.
 *
 * The timer covers everything after the file is in place: the header check,
 * the splitting scan (parallel mode) and every chunk job. Workers are real
 * `queue:work` processes on the importer's queue connection, the same thing
 * Horizon runs, so the numbers include queue overhead. Peak RSS per worker
 * is read with getrusage(RUSAGE_CHILDREN) after they exit.
 */
#[Signature('importer:benchmark
    {file : CSV file to import}
    {--mode=parallel : parallel or sequential}
    {--workers=4 : Concurrent queue workers (0 = run inline, no queue)}
    {--batch-size= : Rows per insert batch (default: config)}
    {--chunk-mb= : Target chunk size in MiB for parallel mode (default: config)}
    {--connection=imports : Queue connection the workers use}
    {--keep : Keep previously imported benchmark rows instead of truncating the data table}
    {--kill-worker-after= : Crash test: SIGKILL one worker after N seconds and start a replacement}
    {--label= : Free-text tag stored with the JSON result}
    {--json= : Append the result as one JSON line to this file}')]
#[Description('Benchmark an end-to-end import (development only)')]
class BenchmarkImportCommand extends Command
{
    private const string BENCH_EMAIL = 'benchmark@example.test';

    public function handle(ImportPipeline $pipeline, DefinitionRegistry $definitions, HeaderValidator $headers): int
    {
        if ($this->laravel->isProduction()) {
            $this->components->error('The benchmark truncates tables and is disabled in production.');

            return self::FAILURE;
        }

        $source = (string) $this->argument('file');
        $mode = ImportMode::tryFrom((string) $this->option('mode'));
        $workers = max(0, (int) $this->option('workers'));

        if (! is_file($source) || $mode === null) {
            $this->components->error('Give an existing file and --mode=parallel or --mode=sequential.');

            return self::FAILURE;
        }

        $definition = $definitions->default();
        $user = $this->benchmarkUser();
        $this->reset($user, $definition->table());

        // Copying the input is setup, not import work: it is outside the timer.
        $disk = Storage::disk(config('importer.disk'));
        $stored = 'bench-'.Str::uuid().'.csv';
        copy($source, $disk->path($stored));

        $mapping = $headers->validate($disk->path($stored), $definition);

        if (! $mapping->isValid()) {
            $this->components->error(implode(' ', $mapping->problems()));

            return self::FAILURE;
        }

        $options = array_filter([
            'insert_batch_size' => $this->option('batch-size') !== null ? (int) $this->option('batch-size') : null,
            'chunk_bytes' => $this->option('chunk-mb') !== null ? (int) ((float) $this->option('chunk-mb') * 1_048_576) : null,
        ]);

        $connection = $workers === 0 ? 'sync' : (string) $this->option('connection');
        $queue = (string) config('importer.queue.name');
        config(['importer.queue.connection' => $connection]);

        if ($workers > 0) {
            Queue::connection($connection)->clear($queue);
        }

        $import = $pipeline->create($user, $definition, $mapping, $stored, basename($source), $mode, $options);

        memory_reset_peak_usage();
        $started = hrtime(true);

        $pipeline->start($import); // validating + split + dispatch (runs the whole import when inline)
        $import->refresh();
        $prepareSeconds = (hrtime(true) - $started) / 1e9;
        $spawned = 0;
        $seconds = $prepareSeconds;

        if ($workers > 0) {
            $spawned = min($workers, max(1, $import->chunk_count));
            $killAfter = $this->option('kill-worker-after');
            $seconds = $this->runWorkers($spawned, $connection, $queue, $import, $started, $killAfter !== null ? (float) $killAfter : null);
        }

        $import->refresh();
        $chunkPeaks = $import->chunks()->pluck('peak_memory_bytes')->filter()->map(fn ($bytes) => (int) $bytes);
        $children = getrusage(1); // RUSAGE_CHILDREN: the largest worker that has exited
        $workerRssMib = $spawned > 0 && $children !== false ? round($children['ru_maxrss'] / 1024, 1) : null;

        $result = [
            'label' => $this->option('label'),
            'file' => basename($source),
            'bytes' => $import->size_bytes,
            'mode' => $import->mode->value,
            'workers' => $spawned,
            'chunks' => $import->chunk_count,
            'insert_batch_size' => $import->option('insert_batch_size', (int) config('importer.insert_batch_size')),
            'chunk_bytes' => $import->mode === ImportMode::Parallel ? $import->option('chunk_bytes', (int) config('importer.chunk_bytes')) : null,
            'status' => $import->status->value,
            'rows_processed' => $import->rows_processed,
            'rows_imported' => $import->rows_imported,
            'rows_failed' => $import->rows_failed,
            'rows_in_table' => DB::table($definition->table())->where('import_id', $import->id)->count(),
            'chunk_attempts' => $import->chunks()->sum('attempts'),
            'prepare_seconds' => round($prepareSeconds, 3),
            'wall_seconds' => round($seconds, 3),
            'rows_per_second' => $seconds > 0 ? (int) round($import->rows_processed / $seconds) : null,
            'job_peak_memory_mib_max' => $chunkPeaks->isEmpty() ? null : round($chunkPeaks->max() / 1_048_576, 1),
            'job_peak_memory_mib_avg' => $chunkPeaks->isEmpty() ? null : round($chunkPeaks->avg() / 1_048_576, 1),
            'worker_peak_rss_mib' => $workerRssMib,
            'coordinator_peak_memory_mib' => round(memory_get_peak_usage(true) / 1_048_576, 1),
            'php' => PHP_VERSION,
        ];

        $this->report($result);

        if ($path = $this->option('json')) {
            file_put_contents((string) $path, json_encode($result).PHP_EOL, FILE_APPEND);
        }

        $disk->delete($stored);

        return $import->rows_processed > 0 ? self::SUCCESS : self::FAILURE;
    }

    private function benchmarkUser(): User
    {
        return User::query()->firstOrCreate(
            ['email' => self::BENCH_EMAIL],
            ['name' => 'Benchmark', 'password' => Str::password(32)],
        );
    }

    /**
     * Starts every run from the same state: no earlier benchmark imports
     * and (unless --keep) an empty data table.
     */
    private function reset(User $user, string $table): void
    {
        Import::query()->whereBelongsTo($user)->delete();

        if (! $this->option('keep')) {
            DB::table($table)->truncate();
        }
    }

    /**
     * Starts the workers, stops the clock the moment the import reaches a
     * final status, then shuts the workers down. (Waiting for them to exit
     * on their own would add the queue's blocking-pop timeout to the time.)
     *
     * @return float Seconds from $started until the import finished.
     */
    private function runWorkers(int $count, string $connection, string $queue, Import $import, int|float $started, ?float $killAfter = null): float
    {
        $processes = [];

        for ($i = 0; $i < $count; $i++) {
            $processes[] = $this->startWorker($connection, $queue);
        }

        while (true) {
            if ($killAfter !== null && (hrtime(true) - $started) / 1e9 >= $killAfter) {
                $killAfter = null;
                $processes[0]->signal(SIGKILL);
                $this->components->warn('Killed worker '.$processes[0]->getPid().' with SIGKILL; starting a replacement.');
                // The killed job stays reserved until the connection's
                // retry_after passes, so this worker must not stop when the
                // queue merely looks empty.
                $processes[] = $this->startWorker($connection, $queue, stopWhenEmpty: false);
            }

            $finished = $import->refresh()->status->isFinished();
            $running = array_filter($processes, fn (Process $process) => $process->isRunning());

            if ($finished || $running === []) {
                break;
            }

            usleep(20_000);
        }

        $seconds = (hrtime(true) - $started) / 1e9;

        foreach ($processes as $process) {
            $process->stop(10, SIGTERM);

            if ($process->getExitCode() !== null && $process->getExitCode() > 0 && $process->getExitCode() !== 143) {
                $this->components->warn('A worker exited with code '.$process->getExitCode().': '.trim($process->getErrorOutput()));
            }
        }

        return $seconds;
    }

    private function startWorker(string $connection, string $queue, bool $stopWhenEmpty = true): Process
    {
        $command = [
            PHP_BINARY, base_path('artisan'), 'queue:work', $connection,
            '--queue='.$queue, '--sleep=0', '--memory=1024',
            '--tries='.config('importer.queue.tries'),
            '--timeout='.config('importer.queue.timeout'),
        ];

        if ($stopWhenEmpty) {
            $command[] = '--stop-when-empty';
        }

        $process = new Process($command, base_path(), timeout: null);
        $process->start();

        return $process;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function report(array $result): void
    {
        $this->newLine();
        $this->components->info(sprintf('%s: %s, %s',
            $result['file'],
            $result['mode'],
            $result['workers'] > 0 ? $result['workers'].' worker(s)' : 'inline',
        ));

        foreach ([
            'Status' => $result['status'],
            'Rows read / imported / failed' => sprintf('%s / %s / %s', number_format($result['rows_processed']), number_format($result['rows_imported']), number_format($result['rows_failed'])),
            'Rows in table' => number_format($result['rows_in_table']),
            'Chunks (job attempts)' => $result['chunks'].' ('.$result['chunk_attempts'].')',
            'Insert batch size' => number_format($result['insert_batch_size']),
            'Prepare (header + split + dispatch)' => $result['prepare_seconds'].' s',
            'Wall time' => $result['wall_seconds'].' s',
            'Rows / second' => number_format((int) $result['rows_per_second']),
            'Peak memory per job (max / avg)' => ($result['job_peak_memory_mib_max'] ?? '–').' / '.($result['job_peak_memory_mib_avg'] ?? '–').' MiB',
            'Peak RSS of any worker process' => ($result['worker_peak_rss_mib'] ?? '–').' MiB',
            'Coordinator peak memory' => $result['coordinator_peak_memory_mib'].' MiB',
        ] as $label => $value) {
            $this->components->twoColumnDetail($label, (string) $value);
        }
    }
}
