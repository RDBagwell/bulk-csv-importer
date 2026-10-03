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
