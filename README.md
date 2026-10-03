# Bulk CSV Importer

Import millions of CSV rows into MySQL **reliably, quickly and safely, with
flat memory use**, and watch it happen live in the browser.

This project began as a small exercise: a Laravel app that accepted a CSV
upload, cut it into 100-row chunks and processed them as a queued batch. It
works for a few thousand rows. At a million rows it runs out of memory. This
rebuild solves the same problem properly and measures both versions on the
same machine, with the same data.

## Before and after: 1,000,000 rows

{{SUMMARY_TABLE}}

Measured on a 4 vCPU / 15 GiB cloud VM with MySQL 8.4 (binary log on, fully
durable commits) on the same machine. Every number, the exact commands and
the full sweeps are in **[docs/BENCHMARKS.md](docs/BENCHMARKS.md)**.

![Import detail page while a 1M-row file is processed in parallel](docs/images/import-progress.png)

## How it works

```mermaid
flowchart LR
    browser([Browser]) -- "upload (multipart)" --> web["Web request<br/>size · type · header row"]
    web -- "move, random name" --> disk[("private disk")]
    web -- "import: pending" --> db[("MySQL")]
    web -- "StartImport(id)" --> queue[["Redis queue"]]
    queue --> start["StartImport<br/>validating"]
    start -- "one quote-aware pass" --> disk
    start -- "chunks: byte ranges<br/>on record boundaries" --> db
    start -- "batch of ProcessImportChunk(chunk id)" --> queue
    queue --> w1["Worker 1"] & w2["Worker 2"] & wn["Worker n"]
    w1 & w2 & wn -- "read own byte range" --> disk
    w1 & w2 & wn -- "validate · upsert batch +<br/>checkpoint in one transaction" --> db
    w1 & w2 & wn -- "invalid rows (capped)" --> db
    queue -. "batch finished" .-> fin["finalize<br/>sum chunks → final status"]
    fin --> db
    browser -. "poll status every 2 s" .-> web
```

1. **Upload.** Authenticated users only. Size, extension, sniffed content
   type and the **header row** are checked before anything is stored or
   queued. The file goes to a private disk under a random name.
2. **Validate and split** (queued job). The header is mapped by name, in any
   order. In parallel mode, one streaming pass finds byte offsets that are
   safe to split at, which needs **quote state**: a quoted field may contain
   newlines, so "seek, then find the next `\n`" would cut records in half.
   The same pass counts the rows exactly.
3. **Process** (job batch). Each job gets a chunk id, never row data, and
   streams its byte range with league/csv. Rows are validated by the
   importer definition, then written in batches with an upsert on
   `(import_id, line_number)`. Each batch commits **with** the chunk's
   counters and resume point, so a killed worker resumes where it stopped
   and nothing is ever written twice.
4. **Report.** Invalid rows are recorded (line, column, message, excerpt)
   without stopping the import, up to a cap; a configurable error rate fails
   it early. The UI polls a light status endpoint for progress, rows/s and
   ETA, and offers the errors as a CSV safe to open in a spreadsheet.

Status lifecycle: `pending → validating → processing → completed |
completed_with_errors | failed | cancelled`, with one transition table and a
conditional update so two actors (a finishing worker and a user pressing
Cancel) cannot both win. **Cancel** stops the remaining chunks; **Retry**
re-runs only the chunks that failed.

## Key decisions and trade-offs

{{DECISIONS}}

## Run it

Requirements: Docker with Compose, and `make`.

```bash
git clone https://github.com/RDBagwell/bulk-csv-importer.git
cd bulk-csv-importer
make up
```

`make up` copies `.env.example` to `.env`, builds the app image, starts
**app** (PHP-FPM + nginx), **horizon** (queue workers), **scheduler**,
**MySQL 8.4**, **Redis** and **Mailpit**, installs dependencies, builds the
front end and runs the migrations. Then:

- App: <http://localhost:8080> (register an account, then **New import**)
- Horizon: <http://localhost:8080/horizon>
- Mailpit (password-reset mail): <http://localhost:8025>

Other targets: `make test`, `make lint`, `make analyse`, `make shell`,
`make logs`, `make down`. On a host without IPv6, set
`NGINX_LISTEN_IP_PROTOCOL=ipv4` in `.env`.

### Large uploads

One variable, `UPLOAD_LIMIT` in `.env` (default `1100M`), sets nginx's
`client_max_body_size` and PHP's `upload_max_filesize` and `post_max_size`
in the containers; `IMPORTER_MAX_UPLOAD_KB` (default 1 GiB) is what Laravel
validates and what the browser checks before uploading. Keep `UPLOAD_LIMIT`
slightly above the Laravel limit for multipart overhead. If they disagree,
the smallest wins: nginx answers 413, PHP drops the file, and only the
Laravel limit produces a friendly message. Outside Docker, set the same
three PHP/nginx values yourself (`php artisan serve` uses your CLI
`php.ini`, whose default `post_max_size` is 8 MiB).

### Configuration

All importer settings live in [`config/importer.php`](config/importer.php)
and can be set from `.env`: mode (`auto`, `sequential`, `parallel`), chunk
size, insert batch size, worker count, retries, error cap and threshold,
retention, upload limits and rate limit.

## Generate data and benchmark

```bash
# Seeded, streamed to disk; the same seed always gives the same bytes.
php artisan importer:generate 1000000                      # storage/app/generated/trade-1000000.csv
php artisan importer:generate 100000 --errors=1 --quoted-newlines --out=/tmp/messy.csv

# One end-to-end import with real queue workers; prints wall time, rows/s and memory.
php artisan importer:benchmark storage/app/generated/trade-1000000.csv --mode=parallel --workers=4
php artisan importer:benchmark storage/app/generated/trade-1000000.csv --mode=sequential --batch-size=5000

# Crash test: SIGKILL a worker mid-import and check the counts are still exact.
IMPORTER_RETRY_AFTER=15 php artisan importer:benchmark trade-1000000.csv --mode=sequential --kill-worker-after=8

# The whole suite behind docs/BENCHMARKS.md, and the tables.
benchmarks/run-suite.sh storage/app/generated
php benchmarks/summarize.php storage/benchmarks/results.jsonl
```

The benchmark truncates the data table, so it refuses to run in production.
Stop Horizon first so it does not compete for the queue.

## Tests

```bash
php artisan test   # Pest: unit + feature, SQLite in memory (CI also runs them on MySQL)
npm test           # Vitest
```

Covered: the splitter (quoted newlines, CRLF, BOM, missing final newline,
empty file, stray quotes, plus a fuzz test against a whole-file parse);
header validation; every validation rule; the error cap and threshold;
idempotency (a chunk processed twice, and a worker killed mid-chunk);
state transitions, cancel and retry-only-failed; authorization for every
action; CSV injection in the export; an end-to-end import of a generated
10k-row file with 1% bad rows, with exact counts; the progress component
and the upload checks.

## What to look at

- **The splitter**: [`app/Importing/Csv/RecordBoundaryScanner.php`](app/Importing/Csv/RecordBoundaryScanner.php)
  and the byte-range reader [`RangeReader`](app/Importing/Csv/RangeReader.php) /
  [`ByteRangeStream`](app/Importing/Csv/ByteRangeStream.php).
- **The importer definition**: [`app/Importing/Definitions/TradeStatisticsDefinition.php`](app/Importing/Definitions/TradeStatisticsDefinition.php),
  its [`ImportDefinition`](app/Importing/Definitions/ImportDefinition.php) contract and the
  [rules](app/Importing/Rules). A second dataset is a new class plus a migration.
- **The idempotent insert and checkpoint**: `flush()` in
  [`app/Importing/Processing/ChunkProcessor.php`](app/Importing/Processing/ChunkProcessor.php),
  and the [data table migration](database/migrations/2026_10_01_000002_create_trade_statistics_table.php).
- **Orchestration and the state machine**: [`ImportPipeline`](app/Importing/ImportPipeline.php),
  [`ImportStatus`](app/Enums/ImportStatus.php) and `Import::transitionTo()`.
- **The benchmark command**: [`app/Console/Commands/BenchmarkImportCommand.php`](app/Console/Commands/BenchmarkImportCommand.php).
- **Security**: [SECURITY.md](SECURITY.md).

## Stack

Laravel 13 · PHP 8.4 (Docker) · React 19 + Inertia 3 + TypeScript (official
starter kit) · league/csv 9 · Laravel Horizon · MySQL 8.4 · Redis 7 · Pest ·
Vitest · Larastan level 7 · Pint.
