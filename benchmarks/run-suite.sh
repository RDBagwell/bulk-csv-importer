#!/usr/bin/env bash
#
# Runs every benchmark behind docs/BENCHMARKS.md and appends one JSON line
# per run to $OUT. Summarise with: php benchmarks/summarize.php $OUT
#
#   benchmarks/run-suite.sh /path/to/generated/files
#
# Needs the four input files from `php artisan importer:generate`
# (trade-10000.csv, trade-100000.csv, trade-1000000.csv, trade-5000000.csv),
# MySQL and Redis reachable with the settings in .env, and nothing else
# consuming the imports queue (stop Horizon). Each run truncates the data
# table first. BETWEEN_RUNS, if set, is eval'd after each run, e.g. to purge
# MySQL binary logs on a small disk:
#   BETWEEN_RUNS='docker exec mysql mysql -uroot -psecret -e "PURGE BINARY LOGS BEFORE NOW()"'

set -euo pipefail

DIR=${1:?usage: run-suite.sh <dir with trade-*.csv>}
OUT=${OUT:-storage/benchmarks/results.jsonl}
REPS=${REPS:-3}
mkdir -p "$(dirname "$OUT")"

bench() {
    local label=$1; shift
    php artisan importer:benchmark "$@" --label="$label" --json="$OUT" | grep -E 'Wall time|Rows / second|Status' | tr -s ' .' ' ' | paste -sd' ' | sed "s/^/[$label] /"
    if [ -n "${BETWEEN_RUNS:-}" ]; then eval "$BETWEEN_RUNS" > /dev/null 2>&1 || true; fi
}

for _ in $(seq "$REPS"); do
    # Sequential at several sizes; parallel (4 workers, 1 MiB chunks) at the same sizes.
    for rows in 10000 100000 1000000; do
        bench "size-seq"   "$DIR/trade-$rows.csv" --mode=sequential --workers=1
        bench "size-par4"  "$DIR/trade-$rows.csv" --mode=parallel --workers=4 --chunk-mb=1
    done

    # Chunk size at 1M rows, 4 workers.
    for mb in 1 2 4 8; do
        bench "chunk-mb" "$DIR/trade-1000000.csv" --mode=parallel --workers=4 --chunk-mb="$mb"
    done

    # Worker count at 1M rows (2 MiB chunks, so there are more chunks than workers).
    for workers in 1 2 4 8; do
        bench "workers" "$DIR/trade-1000000.csv" --mode=parallel --workers="$workers" --chunk-mb=2
    done

    # Insert batch size at 1M rows, sequential (one writer isolates the insert cost).
    for size in 500 1000 2000 5000 10000; do
        bench "batch" "$DIR/trade-1000000.csv" --mode=sequential --workers=1 --batch-size="$size"
    done
done

# Large files, once each.
bench "large-seq"  "$DIR/trade-5000000.csv" --mode=sequential --workers=1
bench "large-par4" "$DIR/trade-5000000.csv" --mode=parallel --workers=4 --chunk-mb=2

# Crash tests: SIGKILL a worker mid-import; the job is redelivered after
# retry_after (15 s here) and must finish with exact counts.
IMPORTER_RETRY_AFTER=15 bench "crash-seq"  "$DIR/trade-1000000.csv" --mode=sequential --workers=1 --kill-worker-after=8
IMPORTER_RETRY_AFTER=15 bench "crash-par4" "$DIR/trade-1000000.csv" --mode=parallel --workers=4 --chunk-mb=2 --kill-worker-after=5
