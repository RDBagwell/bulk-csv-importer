# Security

How the importer handles each item of its security checklist, where to find
the code, and what is tested. To report a vulnerability, please open a
private security advisory on GitHub rather than a public issue.

## Authentication and authorization

- **Every route requires login.** The import routes sit in one `auth`
  middleware group in [`routes/web.php`](routes/web.php); authentication is
  the starter kit's (Fortify). The original app's routes were in `api.php`
  with no auth at all. Tested for every route in
  `ImportHttpTest › authentication`.
- **Ownership is enforced by a policy**, not by filtering in the UI.
  [`ImportPolicy`](app/Policies/ImportPolicy.php) allows view, status,
  error download, cancel and retry only for the owner, and every controller
  calls `Gate::authorize()`. Denials are returned as **404**, so another
  user's import ids cannot be probed. The list query is scoped to
  `$request->user()->imports()`. Tested in `ImportHttpTest › authorization`.
- The Horizon dashboard is open in `APP_ENV=local` only; elsewhere it is
  limited to the e-mail addresses in `HORIZON_ADMINS`.

## Mass assignment

- Models declare explicit `#[Fillable]` lists. `status` is not fillable on
  `Import`: it only changes through `Import::transitionTo()`.
  `ImportError` has no fillable attributes at all (it is written in bulk).
  `Model::shouldBeStrict()` is on outside production, so silently discarded
  attributes and lazy loading fail loudly in development and tests.
- **CSV headers are never used as attribute keys.** The original passed
  `array_combine($header, $row)` to `CsvUpload::create()` with
  `$guarded = []`, so a file with an `id` or `created_at` column wrote
  straight into those columns (confirmed in
  [docs/BENCHMARKS.md](docs/BENCHMARKS.md#defects-confirmed)). Now
  [`HeaderValidator`](app/Importing/Csv/HeaderValidator.php) only uses the
  header to find *positions* of the columns the definition declares; the
  attribute names come from the definition class. Unknown columns (`id`,
  `import_id`, …) reject the file. `import_id` and `line_number` are set by
  the pipeline. Tested in `HeaderValidatorTest` and `ImportHttpTest`.

## Uploads

- **Size:** `IMPORTER_MAX_UPLOAD_KB` (default 1 GiB) is validated by Laravel,
  and the same limit is passed to nginx (`client_max_body_size`) and PHP
  (`upload_max_filesize`, `post_max_size`) by Docker Compose from one
  variable, `UPLOAD_LIMIT`, so the three cannot drift apart. The browser
  checks the size before uploading.
- **Type:** the client-side extension must be `.csv` or `.txt` *and* the
  content, sniffed with libmagic, must be text (`text/plain`, `text/csv`).
  A PNG renamed to `.csv` is rejected. Tested.
- **Header row:** validated against the definition before the file is kept
  or anything is queued. A bad header never creates an import.
- **Storage:** files are moved to the private `imports` disk
  (`storage/app/private/imports`, outside `public/`, `serve => false`)
  under a random UUID name. The client's file name is kept only for
  display, with path components and control characters stripped.
- **Retention:** `importer:prune-files`, scheduled daily, deletes the
  uploaded file of every finished import older than
  `IMPORTER_RETENTION_DAYS` (default 7). Running imports are never pruned.
  The import record and its error report remain.
- **Rate limiting:** `IMPORTER_UPLOADS_PER_MINUTE` (default 10) uploads per
  user per minute (`throttle:imports`). Tested.

## Output

- **CSV / formula injection is neutralised in the error report.** Every
  cell goes through league/csv's `EscapeFormula`, which prefixes cells
  starting with `=`, `+`, `-`, `@`, tab or carriage return with `'`.
  The excerpt column contains raw file contents, so this matters. Tested
  with each trigger character in `ImportHttpTest › error report`. The
  download is served with `X-Content-Type-Options: nosniff` and
  `Cache-Control: no-store`.
- React escapes everything rendered in the UI; header names echoed in
  validation messages are additionally truncated and stripped of control
  characters.

## Errors and logs

- **No stack traces or SQL reach users.** Failure reasons shown in the UI
  are fixed sentences written by the pipeline (e.g. "2 of 21 chunks could
  not be processed…"); exceptions are reported to the log, not displayed.
  Production must run with `APP_DEBUG=false` (the default outside `local`).
- **Logs never contain row contents.** The pipeline never logs rows. The one
  place row data could leak is a database error during a bulk insert:
  Laravel's `QueryException` message contains the SQL *and its bindings*.
  [`ChunkProcessor`](app/Importing/Processing/ChunkProcessor.php) catches it
  and rethrows [`ImportWriteFailed`](app/Importing/Processing/ImportWriteFailed.php),
  which keeps only the table name and SQLSTATE/driver code and drops the
  original exception, so neither the log nor `failed_jobs` sees row data.
  Queue payloads contain only a chunk id, never rows (tested in
  `PipelineTest › never puts row data on the queue`). The PHP image runs
  with `zend.exception_ignore_args=On`, so stack traces carry no argument
  values either.
- Validation messages describe the rule that failed, not the value.

## Database

- **`LOCAL INFILE` is disabled.** `docker/mysql/my.cnf` sets
  `local_infile = 0` (also MySQL 8's default) and the app's PDO connection
  does not enable `MYSQL_ATTR_LOCAL_INFILE`. `LOAD DATA LOCAL` lets a
  client make the server read files from the client machine, and a
  malicious or compromised server can request *any* file the client can
  read. The importer does not need it (see the trade-off in
  [docs/BENCHMARKS.md](docs/BENCHMARKS.md#load-data-comparison)); the
  benchmark script enables it only inside a throwaway container, for the
  duration of the measurement.
- Bulk writes use `INSERT … ON DUPLICATE KEY UPDATE` (no-op) rather than
  `INSERT IGNORE`, so genuine data errors (truncation, out-of-range values)
  still raise instead of being silently coerced.

## Dependencies

At the time of writing:

```console
$ composer audit
No security vulnerability advisories found.
$ npm audit
found 0 vulnerabilities
```

CI runs `composer audit` and `npm audit --omit=dev --audit-level=high` on
every push, and Dependabot is configured for Composer, npm and GitHub
Actions.

## Known limitations

- **A single enormous record.** The parser holds one record in memory at a
  time. A file whose quoting never closes, or with a multi-hundred-megabyte
  field, becomes one huge record and can exhaust the worker's memory. The
  worker is killed and restarted by Horizon and the chunk ends up failed,
  so the impact is limited to that import, but the import fails late
  rather than early. The splitter scan could reject records longer than a
  configured maximum before any worker reads them; this is a planned
  improvement, not implemented.
- Uploaded files are not scanned for malware. They are never executed or
  served back, only parsed as CSV.
