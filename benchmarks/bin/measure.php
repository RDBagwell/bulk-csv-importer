<?php

/**
 * Runs a command and reports its wall time and peak resident memory.
 *
 *   php benchmarks/bin/measure.php -- php artisan queue:work --stop-when-empty
 *
 * Peak RSS comes from getrusage(RUSAGE_CHILDREN), which covers the child and
 * any descendants it waited for. This stands in for `/usr/bin/time -v` on
 * machines where GNU time is not installed. Output is one JSON line on stderr
 * so it can be captured separately from the command's own output.
 */
$separator = array_search('--', $argv, true);
$command = $separator === false ? array_slice($argv, 1) : array_slice($argv, $separator + 1);

if ($command === []) {
    fwrite(STDERR, "usage: php measure.php -- <command> [args...]\n");
    exit(2);
}

$started = hrtime(true);
$process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);

if (! is_resource($process)) {
    fwrite(STDERR, "unable to start command\n");
    exit(2);
}

$exitCode = proc_close($process);
$seconds = (hrtime(true) - $started) / 1e9;
$usage = getrusage(1); // RUSAGE_CHILDREN; ru_maxrss is in KiB on Linux

fwrite(STDERR, json_encode([
    'exit_code' => $exitCode,
    'wall_seconds' => round($seconds, 3),
    'max_rss_mib' => round($usage['ru_maxrss'] / 1024, 1),
]).PHP_EOL);

exit($exitCode);
