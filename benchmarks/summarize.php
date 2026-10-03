<?php

/**
 * Turns the JSON lines written by `importer:benchmark --json=...` into the
 * Markdown tables used in docs/BENCHMARKS.md. Repeated runs of the same
 * configuration are reduced to their median wall time; the run count and
 * the min–max range are shown next to it.
 *
 *   php benchmarks/summarize.php storage/benchmarks/results.jsonl
 */
$path = $argv[1] ?? 'storage/benchmarks/results.jsonl';
$runs = array_map(fn ($line) => json_decode($line, true), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

$groups = [];

foreach ($runs as $run) {
    $key = implode('|', [$run['label'], $run['file'], $run['mode'], $run['workers'], $run['chunk_bytes'] ?? '-', $run['insert_batch_size']]);
    $groups[$key][] = $run;
}

function median(array $values): float
{
    sort($values);
    $n = count($values);

    return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

$byLabel = [];

foreach ($groups as $runsInGroup) {
    $first = $runsInGroup[0];
    $walls = array_column($runsInGroup, 'wall_seconds');
    $median = median($walls);
    $allExact = array_reduce($runsInGroup, fn ($ok, $r) => $ok && $r['rows_in_table'] === $r['rows_imported'] && $r['rows_processed'] === $r['rows_imported'] + $r['rows_failed'], true);

    $byLabel[$first['label']][] = [
        'file' => $first['file'],
        'rows' => $first['rows_processed'],
        'mode' => $first['mode'],
        'workers' => $first['workers'],
        'chunks' => $first['chunks'],
        'chunk_mb' => $first['chunk_bytes'] ? $first['chunk_bytes'] / 1_048_576 : null,
        'batch' => $first['insert_batch_size'],
        'runs' => count($walls),
        'median' => $median,
        'min' => min($walls),
        'max' => max($walls),
        'rows_per_second' => $median > 0 ? (int) round($first['rows_processed'] / $median) : 0,
        'job_mem' => max(array_column($runsInGroup, 'job_peak_memory_mib_max')),
        'rss' => max(array_map(fn ($r) => $r['worker_peak_rss_mib'] ?? 0, $runsInGroup)),
        'attempts' => max(array_column($runsInGroup, 'chunk_attempts')),
        'status' => implode(', ', array_unique(array_column($runsInGroup, 'status'))),
        'exact' => $allExact,
    ];
}

foreach ($byLabel as $label => $rows) {
    echo "### {$label}\n\n";
    echo "| Rows | Mode | Workers | Chunks | Chunk | Batch | Runs | Wall (median) | Range | Rows/s | Job peak mem | Worker peak RSS | Job attempts | Status | Counts exact |\n";
    echo "|---:|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|---|\n";

    foreach ($rows as $r) {
        printf(
            "| %s | %s | %d | %d | %s | %s | %d | **%.2f s** | %.2f–%.2f | %s | %s MiB | %s MiB | %d | %s | %s |\n",
            number_format($r['rows']), $r['mode'], $r['workers'], $r['chunks'],
            $r['chunk_mb'] === null ? '–' : rtrim(rtrim(number_format($r['chunk_mb'], 2), '0'), '.').' MiB',
            number_format($r['batch']), $r['runs'], $r['median'], $r['min'], $r['max'],
            number_format($r['rows_per_second']), $r['job_mem'], $r['rss'], $r['attempts'], $r['status'], $r['exact'] ? 'yes' : '**no**',
        );
    }

    echo "\n";
}
