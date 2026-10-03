#!/usr/bin/env bash
#
# Benchmarks the ORIGINAL implementation (the commit before the rebuild) so
# the "before" numbers in docs/BENCHMARKS.md can be reproduced.
#
#   benchmarks/legacy/run.sh storage/app/generated/trade-10000.csv [more files...]
#
# What it does for each file:
#   1. Checks out the legacy commit into a git worktree (once) and installs it.
#   2. Resets the database (migrate:fresh).
#   3. Serves the app with PHP's built-in server and POSTs the file to the
#      legacy /api/upload endpoint, recording HTTP status, request time and the
#      server's peak RSS (VmHWM).
#   4. Drains the queue with WORKERS `queue:work --stop-when-empty` processes,
#      recording wall time and peak RSS per worker.
#   5. Counts imported rows and failed jobs.
#
# Environment (defaults in brackets):
#   LEGACY_REF [41cc44c]  MEMORY_LIMIT [128M]  UPLOAD_LIMIT [2G]  WORKERS [1]
#   DB_HOST [127.0.0.1]  DB_PORT [3306]  DB_DATABASE [legacy]  DB_USERNAME [root]  DB_PASSWORD [secret]
#   WORKER_TIMEOUT [3600] seconds before the drain is abandoned
#
# PHP's memory_limit applies to both the upload request and the workers.
# The legacy lock file targets PHP < 8.3, so dependencies are installed with
# --ignore-platform-req=php to run on the same PHP as the new implementation.

set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
LEGACY_REF=${LEGACY_REF:-41cc44c}
LEGACY_DIR=${LEGACY_DIR:-$ROOT/storage/benchmarks/legacy-app}
MEMORY_LIMIT=${MEMORY_LIMIT:-128M}
UPLOAD_LIMIT=${UPLOAD_LIMIT:-2G}
WORKERS=${WORKERS:-1}
WORKER_TIMEOUT=${WORKER_TIMEOUT:-3600}
DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=${DB_PORT:-3306}
DB_DATABASE=${DB_DATABASE:-legacy}
DB_USERNAME=${DB_USERNAME:-root}
DB_PASSWORD=${DB_PASSWORD:-secret}
PORT=${PORT:-8010}
MEASURE="$ROOT/benchmarks/bin/measure.php"
RESULTS_DIR="$ROOT/storage/benchmarks/legacy-results"

sql() {
    php -r '
        $pdo = new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
        echo $pdo->query($argv[1])->fetchColumn();
    ' "$1"
}
export DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD

if [ ! -d "$LEGACY_DIR" ]; then
    git -C "$ROOT" worktree add --detach "$LEGACY_DIR" "$LEGACY_REF"
    (cd "$LEGACY_DIR" && composer install --no-interaction --prefer-source --ignore-platform-req=php)
fi

cat > "$LEGACY_DIR/.env" <<ENV
APP_NAME=Legacy
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://127.0.0.1:${PORT}
LOG_CHANNEL=single
DB_CONNECTION=mysql
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}
CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=database
ENV
(cd "$LEGACY_DIR" && php artisan key:generate -q)
mkdir -p "$RESULTS_DIR"

for file in "$@"; do
    file=$(cd "$(dirname "$file")" && pwd)/$(basename "$file")
    name=$(basename "$file" .csv)
    rows=$(( $(wc -l < "$file") - 1 ))
    out="$RESULTS_DIR/$name-mem${MEMORY_LIMIT}-w${WORKERS}"
    rm -f "$out".* "$LEGACY_DIR/storage/logs/laravel.log"

    echo "== $name ($rows rows, memory_limit=$MEMORY_LIMIT, workers=$WORKERS)"
    (cd "$LEGACY_DIR" && php artisan migrate:fresh --force -q)

    # Same invocation as `artisan serve`: run from public/ with the framework router.
    (cd "$LEGACY_DIR/public" && exec php -d memory_limit="$MEMORY_LIMIT" -d upload_max_filesize="$UPLOAD_LIMIT" \
        -d post_max_size="$UPLOAD_LIMIT" -d max_execution_time=0 \
        -S "127.0.0.1:$PORT" -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php \
        > "$out.server.log" 2>&1) &
    server=$!
    for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$PORT/up" && break || sleep 0.1; done

    read -r http_code request_seconds < <(curl -s -o "$out.response" -w '%{http_code} %{time_total}\n' \
        -H 'Accept: application/json' -F "mycsv=@$file" "http://127.0.0.1:$PORT/api/upload")
    request_rss_kib=$(awk '/VmHWM/ {print $2}' "/proc/$server/status")
    kill "$server"; wait "$server" 2>/dev/null || true

    queued=$(sql "select count(*) from jobs")
    echo "   upload: HTTP $http_code in ${request_seconds}s, peak RSS $((request_rss_kib / 1024)) MiB, $queued jobs queued"
    grep -h -m1 -o 'Allowed memory size.*' "$out.server.log" "$LEGACY_DIR/storage/logs/laravel.log" 2>/dev/null | head -1 | sed 's/^/   /' || true

    started=$(date +%s.%N)
    pids=()
    for i in $(seq 1 "$WORKERS"); do
        (cd "$LEGACY_DIR" && exec timeout "$WORKER_TIMEOUT" php "$MEASURE" -- php -d memory_limit="$MEMORY_LIMIT" artisan queue:work database \
            --stop-when-empty --tries=1 --memory=4096 --sleep=0 > "$out.worker$i.log" 2> "$out.worker$i.json") &
        pids+=($!)
    done
    for pid in "${pids[@]}"; do wait "$pid" || true; done
    drain_seconds=$(echo "$(date +%s.%N) - $started" | bc)

    imported=$(sql "select count(*) from csv_uploads")
    failed=$(sql "select count(*) from failed_jobs")
    remaining=$(sql "select count(*) from jobs")
    worker_rss=$(cat "$out".worker*.json | grep -o '"max_rss_mib":[0-9.]*' | cut -d: -f2 | sort -n | tail -1)
    total=$(echo "$request_seconds + $drain_seconds" | bc)

    echo "   drain: ${drain_seconds}s, worker peak RSS ${worker_rss:-?} MiB"
    echo "   result: $imported / $rows rows imported, $failed failed jobs, $remaining jobs left, total ${total}s"
    printf '%s\n' "{\"file\":\"$name\",\"rows\":$rows,\"memory_limit\":\"$MEMORY_LIMIT\",\"workers\":$WORKERS,\"http_code\":$http_code,\"request_seconds\":$request_seconds,\"request_rss_mib\":$((request_rss_kib / 1024)),\"jobs_queued\":$queued,\"drain_seconds\":$drain_seconds,\"worker_rss_mib\":${worker_rss:-null},\"imported\":$imported,\"failed_jobs\":$failed,\"jobs_left\":$remaining,\"total_seconds\":$total}" > "$out.json"
done
