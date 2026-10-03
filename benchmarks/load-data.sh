#!/usr/bin/env bash
#
# Upper bound: how fast can MySQL load the same file with LOAD DATA, with
# no validation and no per-row error reporting?
#
#   MYSQL_CONTAINER=bench-mysql benchmarks/load-data.sh trade-1000000.csv
#
# Runs entirely inside the MySQL container with the mysql client, so the
# application never needs LOCAL INFILE. The server's local_infile is
# switched on for the duration of the run and restored afterwards. Do not
# point this at a database you care about: it truncates trade_statistics.

set -euo pipefail

FILE=${1:?usage: load-data.sh <file.csv>}
CONTAINER=${MYSQL_CONTAINER:-bench-mysql}
DB=${DB_DATABASE:-importer}
ROOT_PASSWORD=${DB_ROOT_PASSWORD:-secret}
REPS=${REPS:-3}

mysql_exec() {
    docker exec -i "$CONTAINER" mysql --local-infile=1 -uroot -p"$ROOT_PASSWORD" "$DB" -N "$@" 2>/dev/null
}

docker cp "$FILE" "$CONTAINER:/tmp/load-data.csv"
was=$(mysql_exec -e "SELECT @@GLOBAL.local_infile")
mysql_exec -e "SET GLOBAL local_infile = 1"
trap 'mysql_exec -e "SET GLOBAL local_infile = $was"; docker exec "$CONTAINER" rm -f /tmp/load-data.csv' EXIT

for i in $(seq "$REPS"); do
    mysql_exec -e "TRUNCATE trade_statistics"
    mysql_exec -e "PURGE BINARY LOGS BEFORE NOW()" || true
    started=$(date +%s.%N)
    # Line numbers mirror the app's: the header is record 1, data starts at 2.
    mysql_exec -e "
        SET @line = 1;
        LOAD DATA LOCAL INFILE '/tmp/load-data.csv'
        INTO TABLE trade_statistics
        FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"'
        LINES TERMINATED BY '\n'
        IGNORE 1 LINES
        (time_ref, account, code, country_code, product_type, value, status)
        SET import_id = 999999, line_number = (@line := @line + 1);"
    seconds=$(echo "$(date +%s.%N) - $started" | bc)
    rows=$(mysql_exec -e "SELECT COUNT(*) FROM trade_statistics")
    printf '{"label":"load-data","file":"%s","run":%d,"rows":%d,"wall_seconds":%.3f}\n' "$(basename "$FILE")" "$i" "$rows" "$seconds"
done
