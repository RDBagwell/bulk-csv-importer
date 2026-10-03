#!/usr/bin/env bash
#
# Confirms the known defects of the ORIGINAL implementation by uploading
# small crafted files and showing what lands in the table and failed_jobs.
# Run benchmarks/legacy/run.sh once first (it sets up the legacy checkout).
#
#   benchmarks/legacy/probe.sh

set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
LEGACY_DIR=${LEGACY_DIR:-$ROOT/storage/benchmarks/legacy-app}
PORT=${PORT:-8010}
WORK=$(mktemp -d)
export DB_HOST=${DB_HOST:-127.0.0.1} DB_PORT=${DB_PORT:-3306} DB_DATABASE=${DB_DATABASE:-legacy} DB_USERNAME=${DB_USERNAME:-root} DB_PASSWORD=${DB_PASSWORD:-secret}

sql() {
    php -r '
        $pdo = new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
        foreach ($pdo->query($argv[1])->fetchAll(PDO::FETCH_NUM) as $row) { echo implode(" | ", $row), "\n"; }
    ' "$1"
}

HEADER="time_ref,account,code,country_code,product_type,value,status"
GOOD="202401,Exports,01,NZ,Goods,100.00,F"

printf '%s\n%s\n202402,Imports,02,AU,Goods,5.00\n%s\n' "$HEADER" "$GOOD" "$GOOD" > "$WORK/wrong-field-count.csv"
printf 'id,%s\n1,%s\n1,%s\n' "$HEADER" "$GOOD" "$GOOD" > "$WORK/id-column.csv"
printf '%s,is_admin\n%s,1\n' "$HEADER" "$GOOD" > "$WORK/unknown-column.csv"
printf '%s,created_at\n%s,not-a-date\n' "$HEADER" "$GOOD" > "$WORK/created-at-column.csv"
printf '%s\n%s\n202413,Exprts,??,zz,Things,N/A,X\n' "$HEADER" "$GOOD" > "$WORK/invalid-values.csv"

(cd "$LEGACY_DIR/public" && exec php -S "127.0.0.1:$PORT" -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php > /dev/null 2>&1) &
server=$!
trap 'kill $server 2>/dev/null; rm -rf "$WORK"' EXIT
for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$PORT/up" && break || sleep 0.1; done

for file in "$WORK"/*.csv; do
    echo "== $(basename "$file")"
    (cd "$LEGACY_DIR" && php artisan migrate:fresh --force -q)
    curl -s -o /dev/null -w '   upload: HTTP %{http_code}\n' -F "mycsv=@$file" "http://127.0.0.1:$PORT/api/upload"
    (cd "$LEGACY_DIR" && php artisan queue:work database --stop-when-empty --tries=1 --sleep=0 > /dev/null 2>&1 || true)
    echo "   rows stored:"
    sql "select id, time_ref, account, code, country_code, product_type, value, status, created_at from csv_uploads" | sed 's/^/     /'
    echo "   failed jobs:"
    sql "select substring_index(exception, char(10), 1) from failed_jobs" | cut -c1-220 | sed 's/^/     /'
done
