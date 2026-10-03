# Benchmarks

Every number on this page was measured on the machine described below, with
the command shown next to it. Nothing is extrapolated. Where something was
not run, the page says so.

## Machine and settings

| | |
|---|---|
| CPU | 4 vCPU, Intel Xeon @ 2.10 GHz (cloud VM, 1 thread per core) |
| Memory | 15 GiB, no swap |
| Disk | virtio block device, ext4 |
| OS | Linux 6.18 |
| PHP | 8.3.6 CLI, OPcache off for CLI, JIT off |
| MySQL | 8.4.11 in Docker on the same machine; `innodb_buffer_pool_size=1G`, everything else default (binary log **on**, `sync_binlog=1`, `innodb_flush_log_at_trx_commit=1`, doublewrite on) |
| Redis | 7.4.11 in Docker on the same machine |

PHP, MySQL and the workers all share the same 4 vCPUs, so parallel results
are bounded by that. Both implementations ran against the same MySQL server
and the same input files.

### Input files

Generated with the seeded generator (identical bytes on every run):

```bash
php artisan importer:generate 10000      --out=trade-10000.csv      # 0.4 MiB
php artisan importer:generate 100000     --out=trade-100000.csv     # 4.1 MiB
php artisan importer:generate 1000000    --out=trade-1000000.csv    # 40.9 MiB
php artisan importer:generate 5000000    --out=trade-5000000.csv    # 204.5 MiB
```

All rows are valid, because the original implementation cannot survive an
invalid row (see below). The generator itself streams: it wrote the 5M-row
file in 4.1 s with a flat 30 MiB peak.

## Before: the original implementation

Measured with [`benchmarks/legacy/run.sh`](../benchmarks/legacy/run.sh),
which checks out the original commit (`41cc44c`) into a worktree, serves it
with PHP's built-in server, POSTs the file to its `/api/upload` endpoint and
then drains the database queue with `queue:work --stop-when-empty`.
Request memory is the server process's peak RSS; worker memory comes from
[`benchmarks/bin/measure.php`](../benchmarks/bin/measure.php).

```bash
benchmarks/legacy/run.sh trade-10000.csv trade-100000.csv trade-1000000.csv   # memory_limit=128M, 1 worker
MEMORY_LIMIT=-1 benchmarks/legacy/run.sh trade-1000000.csv                     # no memory limit
WORKERS=4 benchmarks/legacy/run.sh trade-100000.csv                            # 4 workers
```

| Rows | PHP memory_limit | Workers | Upload request | Request peak RSS | Queue drain | **Total** | Rows/s | Result |
|---:|---|---:|---:|---:|---:|---:|---:|---|
| 10,000 | 128M | 1 | 0.5 s | 56 MiB | 11.5 s | **12.0 s** | 834 | all rows imported |
| 100,000 | 128M | 1 | 4.6 s | 69 MiB | 114.8 s | **119.4 s** | 838 | all rows imported |
| 100,000 | 128M | 4 | 4.5 s | 69 MiB | 45.9 s | **50.5 s** | 1,982 | all rows imported |
| 1,000,000 | 128M | 1 | 1.4 s | 214 MiB | – | **fails** | – | HTTP 500, `Allowed memory size of 134217728 bytes exhausted` in the controller; nothing queued, 0 rows imported |
| 1,000,000 | unlimited | 1 | 37.3 s | 223 MiB | 1,182.9 s | **1,220.2 s** (20 min 20 s) | 820 | all rows imported |

What happens at 1M rows: `file()` reads all 41 MiB into an array of one
million strings, `array_chunk` copies it, and every 100-row chunk is
serialised *with its row data* into the `jobs` table: 10,001 job rows written
during the request. With PHP's default 128 MiB limit the request dies before
anything is queued. Without a limit the request alone takes 37 s and 223 MiB,
then one `INSERT` per row runs at about 830 rows/s.

Note also that PHP's default `upload_max_filesize` (2 MiB) rejects anything
from about 50,000 rows up before the code even runs; the runs above raise
the upload limits to 2 GiB so the code itself is what is measured.

### Defects confirmed

[`benchmarks/legacy/probe.sh`](../benchmarks/legacy/probe.sh) uploads small
crafted files to the original implementation. Results:

| Input | What happened |
|---|---|
| A row with 6 fields in the middle of a chunk | `ValueError: array_combine(): Argument #1 ($keys) and argument #2 ($values) must have the same number of elements`. The job fails, so **every later row in the same 100-row chunk is silently lost** (the valid row after it was not stored). |
| Header with an `id` column | Mass-assigned straight into the primary key: the first row was stored **with the id from the file**, the second failed with `Duplicate entry '1' for key 'csv_uploads.PRIMARY'`. |
| Header with an unknown column (`is_admin`) | `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'is_admin'`: the job fails. Any column that does exist would have been written. |
| Header with `created_at` = `not-a-date` | `DateMalformedStringException`: the job fails. |
| Invalid values (`202413`, `Exprts`, `zz`, `N/A`, `X`) | **Stored as-is.** There is no validation, and `value` is a string column. |
| Any failed job | The failure handler itself throws: `App\Jobs\DataCsvProcess::failed(): Argument #1 ($exception) must be of type App\Jobs\Throwable` (missing `use Throwable`). |

Also confirmed by reading the code: no authentication on the upload or
batch routes (they sit in `api.php` with no middleware), no size limit and
no file type check.

## After: the streaming importer

Measured with `php artisan importer:benchmark` (see
[`BenchmarkImportCommand`](../app/Console/Commands/BenchmarkImportCommand.php)).
Each run starts from an empty data table, copies the file into place
(untimed), then times everything else: header check, splitting scan,
dispatch and every chunk job, until the import reaches its final status.
Workers are real `queue:work` processes on the Redis `imports` connection,
the same thing Horizon runs. "Job peak mem" is `memory_get_peak_usage(true)`
inside the chunk job; "worker peak RSS" is the largest resident set of any
worker process (`getrusage(RUSAGE_CHILDREN)`), framework included.

The whole suite is one script, and the tables below are its summary
(medians of 3 runs unless stated; raw results in
[`benchmark-results.jsonl`](benchmark-results.jsonl)):

```bash
BETWEEN_RUNS='docker exec bench-mysql mysql -uroot -psecret -e "PURGE BINARY LOGS BEFORE NOW()"' \
  benchmarks/run-suite.sh /path/to/generated/files
php benchmarks/summarize.php storage/benchmarks/results.jsonl
```

"Counts exact" checks, for every run, that rows in the table = rows
imported, and rows read = imported + failed. It held for all 57 runs.

### Sequential vs. parallel by file size

| Rows | Sequential (1 job) | Parallel (4 workers, 1 MiB chunks) | Speed-up |
|---:|---:|---:|---:|
| 10,000 | 0.65 s | 0.63 s (1 chunk) | – |
| 100,000 | 3.46 s | 1.42 s (5 chunks) | 2.4× |
| 1,000,000 | 32.61 s | 11.14 s (41 chunks) | 2.9× |
| 5,000,000 | 157.07 s ¹ | 51.47 s (103 chunks, 2 MiB) ¹ | 3.1× |

¹ single run.

Sequential runs at a steady **~31,000 rows/s** from 100k to 5M rows, with
the same **46.5 MiB** job peak and **75 MiB** worker RSS at every size:
memory does not grow with the file. Parallel is never slower: below about
2 MiB the file is a single chunk anyway, and the extra splitting scan is
cheap (25 ms for the 41 MiB file). **Decision: parallel is the default**
(`IMPORTER_MODE=parallel`); `sequential` stays available, and `auto`
switches at `IMPORTER_PARALLEL_MIN_BYTES`.

### Parallel: workers at 1M rows (2 MiB chunks, 21 chunks)

| Workers | Wall | Rows/s | vs. 1 worker |
|---:|---:|---:|---:|
| 1 | 32.60 s | 30,678 | 1.0× |
| 2 | 17.58 s | 56,886 | 1.9× |
| 4 | 11.16 s | 89,638 | 2.9× |
| 8 | 10.35 s | 96,609 | 3.2× |

Near-linear to 2 workers, then flattening: MySQL and the workers share the
4 vCPUs, so 8 workers on 4 cores mostly add contention. Set
`IMPORTER_WORKERS` to roughly the number of cores the workers get (4 here).

### Parallel: chunk size at 1M rows (4 workers)

| Target chunk | Chunks | Wall | Rows/s |
|---:|---:|---:|---:|
| 1 MiB | 41 | 10.97 s | 91,133 |
| 2 MiB | 21 | 10.94 s | 91,399 |
| 4 MiB | 11 | 11.31 s | 88,441 |
| 8 MiB | 6 | 14.29 s | 69,955 |

With 8 MiB chunks a 41 MiB file has 6 chunks for 4 workers, so two workers
do a second chunk while the others sit idle. Smaller chunks balance better
and the per-chunk overhead (one job, one scan boundary, one batch-of-jobs
entry) is too small to show. **Decision: 2 MiB default**, smaller than the
5–10 MB first proposed, because the measurements say so. A 1 GiB upload is
then ~500 chunks, still trivial for the queue.

### Insert batch size at 1M rows (sequential, one writer)

| Rows per batch | Wall | Rows/s | Job peak mem |
|---:|---:|---:|---:|
| 500 | 37.90 s | 26,387 | 38.5 MiB |
| 1,000 | 32.96 s | 30,342 | 40.5 MiB |
| **2,000** | **32.23 s** | **31,032** | 46.5 MiB |
| 5,000 | 34.34 s | 29,121 | 60.5 MiB |
| 10,000 | 34.66 s | 28,855 | 70.5 MiB |

Each batch is one transaction (rows + checkpoint), so small batches pay one
durable commit per few hundred rows; past ~2,000 the gain stops and the
memory held per batch keeps growing. Above 7,281 rows (65,535 placeholders /
9 columns) a batch is sent as several statements inside the same
transaction. **Decision: 2,000.**

### Crash test: SIGKILL a worker mid-import

```bash
IMPORTER_RETRY_AFTER=15 php artisan importer:benchmark trade-1000000.csv --mode=sequential --workers=1 --kill-worker-after=8
IMPORTER_RETRY_AFTER=15 php artisan importer:benchmark trade-1000000.csv --mode=parallel --workers=4 --chunk-mb=2 --kill-worker-after=5
```

| Mode | Killed after | Wall | Job attempts | Rows in table | Result |
|---|---:|---:|---:|---:|---|
| sequential | 8 s | 43.09 s | 2 (1 chunk) | 1,000,000 | completed, counts exact |
| parallel, 4 workers | 5 s | 21.27 s | 22 (21 chunks) | 1,000,000 | completed, counts exact |

The killed job is redelivered once `retry_after` (15 s for this test,
3,660 s by default) has passed, resumes from its last committed batch, and
the import completes with exactly 1,000,000 rows and no duplicates. Most of
the extra wall time is the redelivery wait, not repeated work.

### LOAD DATA comparison

```bash
MYSQL_CONTAINER=bench-mysql benchmarks/load-data.sh trade-1000000.csv
```

`LOAD DATA LOCAL INFILE` into the same table, run with the `mysql` client
inside the MySQL container: **4.56 / 4.68 / 4.77 s** for 1M rows (median
4.68 s, ~214,000 rows/s), about 2.4× faster than the parallel pipeline.

It does not validate, and it does not report. Loading a 10,000-row file with
500 bad rows (`importer:generate 10000 --errors=5`) the same way:

| | LOAD DATA LOCAL | This importer |
|---|---|---|
| Rows written | **10,000** (every bad row too) | 9,500 |
| Invalid accounts stored | 65 (`Exprts`) | 0 |
| `N/A` values | 49 stored as **0.00** | rejected |
| What the user learns | "267 warnings" for the whole statement | one row per error: line, column, message, excerpt |

With `LOCAL`, MySQL downgrades data errors to warnings and coerces the
values, so bad data silently becomes plausible-looking data. Making it safe
would mean loading into an untyped staging table and re-validating in SQL,
re-implementing every rule of the definition in a second language and
losing the line-level report. It also needs `local_infile` enabled on both
server and client, which lets a (malicious or compromised) server read any
file the app server can read. **Decision: the main pipeline does not use
it**, and `local_infile` stays off (see [SECURITY.md](../SECURITY.md)). For a
trusted, pre-validated feed, LOAD DATA into a staging table would be the
next step for raw speed.

## Before vs. after, 1M rows

| | Original | Rebuild, sequential | Rebuild, parallel (4 workers) |
|---|---:|---:|---:|
| Default PHP memory limit (128 MiB) | **fails** (out of memory in the upload request) | 32.6 s | 11.1 s |
| Wall time | 1,220 s (20 min 20 s) with no memory limit | 32.6 s | 11.1 s |
| Rows/s | 820 | 30,666 | 89,791 |
| Peak memory | 223 MiB upload request (grows with the file) | 46.5 MiB per job, 75 MiB worker RSS (flat) | same, per worker |
| Upload request | 37 s, holds the whole file in memory | header check + move, constant | same |
| Invalid rows | crash the chunk, later rows lost | reported, import continues | same |
| Worker killed mid-import | rows lost or duplicated | resumes, exact counts | same |

Speed-up at 1M rows: **37× sequential, 110× parallel**, and the original
cannot run the 1M file at all under PHP's default memory limit.

## Not measured here

- **The Docker stack itself.** These runs used PHP 8.3 on the host against
  MySQL and Redis in containers, so that the original (whose lock file needs
  PHP < 8.3, installed with `--ignore-platform-req=php`) and the rebuild ran
  on the same PHP. The app image uses PHP 8.4 with OPcache; expect similar
  or slightly better numbers. To measure inside the stack:
  `docker compose exec app php artisan importer:benchmark …` after
  `docker compose stop horizon`.
- **Uploads over HTTP at 1 GiB.** The upload path is a single streamed move
  plus a header read and was exercised in the browser at 41 MiB; a
  1 GiB upload depends mostly on the network and disk of the machine.
- **Faster disks or a separate database host.** With MySQL on its own
  machine the parallel numbers should scale further than the 4 shared
  vCPUs allow here.
