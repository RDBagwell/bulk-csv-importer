<?php

use App\Enums\ImportStatus;
use App\Jobs\StartImport;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use League\Csv\Reader;

beforeEach(function () {
    Storage::fake('imports');
});

function csvUpload(string $contents, string $name = 'trade.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

function validCsv(int $rows = 3): string
{
    $csv = "time_ref,account,code,country_code,product_type,value,status\n";

    for ($i = 0; $i < $rows; $i++) {
        $csv .= "202401,Exports,01,NZ,Goods,10.00,F\n";
    }

    return $csv;
}

/**
 * A real file on disk, so the MIME type is sniffed from its content
 * (fake uploads report a type based on the extension).
 */
function realUpload(string $contents, string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, null, null, true);
}

function importOwnedBy(User $user, array $attributes = []): Import
{
    return Import::factory()->for($user)->create($attributes);
}

describe('authentication', function () {
    it('requires login for every import route', function (string $method, string $uri) {
        $import = Import::factory()->create();

        $this->call($method, str_replace('{id}', (string) $import->id, $uri))->assertRedirect(route('login'));
    })->with([
        ['GET', '/imports'],
        ['GET', '/imports/create'],
        ['POST', '/imports'],
        ['GET', '/imports/{id}'],
        ['GET', '/imports/{id}/status'],
        ['GET', '/imports/{id}/errors.csv'],
        ['POST', '/imports/{id}/cancel'],
        ['POST', '/imports/{id}/retry'],
    ]);
});

describe('authorization', function () {
    it('hides other users\' imports behind a 404', function (string $method, string $uri) {
        $owner = User::factory()->create();
        $import = importOwnedBy($owner, ['status' => ImportStatus::Processing]);

        $this->actingAs(User::factory()->create())
            ->call($method, str_replace('{id}', (string) $import->id, $uri))
            ->assertNotFound();

        expect($import->refresh()->status)->toBe(ImportStatus::Processing);
    })->with([
        'view' => ['GET', '/imports/{id}'],
        'status' => ['GET', '/imports/{id}/status'],
        'download errors' => ['GET', '/imports/{id}/errors.csv'],
        'cancel' => ['POST', '/imports/{id}/cancel'],
        'retry' => ['POST', '/imports/{id}/retry'],
    ]);

    it('lists only the user\'s own imports', function () {
        $user = User::factory()->create();
        importOwnedBy($user, ['original_filename' => 'mine.csv']);
        importOwnedBy(User::factory()->create(), ['original_filename' => 'theirs.csv']);

        $this->actingAs($user)->get('/imports')
            ->assertInertia(fn (Assert $page) => $page
                ->component('imports/index')
                ->has('imports.data', 1)
                ->where('imports.data.0.filename', 'mine.csv'));
    });
});

describe('upload', function () {
    it('stores the file under a random name outside the public path and starts the import', function () {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/imports', ['file' => csvUpload(validCsv(), '../../etc/passwd.csv')]);

        $import = Import::query()->sole();
        $response->assertRedirect(route('imports.show', $import));

        expect($import->user_id)->toBe($user->id)
            ->and($import->status)->toBe(ImportStatus::Pending)
            ->and($import->original_filename)->toBe('passwd.csv')
            ->and($import->stored_path)->toMatch('/^[0-9a-f-]{36}\.csv$/')
            ->and(Storage::disk('imports')->exists($import->stored_path))->toBeTrue()
            ->and(config('filesystems.disks.imports.root'))->not->toStartWith(public_path());

        Queue::assertPushed(StartImport::class, fn (StartImport $job) => $job->importId === $import->id);
    });

    it('rejects a bad header before storing or queueing anything', function (string $header, string $message) {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post('/imports', ['file' => csvUpload($header."\n202401,Exports,01,NZ,Goods,1,F\n")])
            ->assertSessionHasErrors(['file' => $message]);

        expect(Import::query()->count())->toBe(0)
            ->and(Storage::disk('imports')->allFiles())->toBe([]);
        Queue::assertNothingPushed();
    })->with([
        'missing' => ['time_ref,account,code', 'The header row is not valid. Missing required columns: country_code, product_type, value, status.'],
        'unknown' => ['time_ref,account,code,country_code,product_type,value,status,id', 'The header row is not valid. Unknown columns: id.'],
        'duplicate' => ['time_ref,account,code,country_code,product_type,value,status,status', 'The header row is not valid. Duplicate columns: status.'],
    ]);

    it('accepts columns in any order', function () {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post('/imports', ['file' => csvUpload("status,value,product_type,country_code,code,account,time_ref\nF,1,Goods,NZ,01,Exports,202401\n")])
            ->assertSessionHasNoErrors();

        expect(Import::query()->sole()->column_map)->toMatchArray(['status' => 0, 'time_ref' => 6]);
    });

    it('rejects files that are not CSV', function (UploadedFile $file, string $message) {
        $this->actingAs(User::factory()->create())
            ->post('/imports', ['file' => $file])
            ->assertSessionHasErrors(['file' => $message]);

        expect(Import::query()->count())->toBe(0);
    })->with([
        'wrong extension' => fn () => [csvUpload(validCsv(), 'trade.xlsx'), 'The file must be a .csv file.'],
        'binary content' => fn () => [realUpload(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), 'photo.csv'), 'The file does not look like CSV text.'],
        'empty' => fn () => [realUpload('', 'empty.csv'), 'The file does not look like CSV text.'],
    ]);

    it('rejects files over the configured size limit', function () {
        config(['importer.max_upload_kb' => 1]);

        $this->actingAs(User::factory()->create())
            ->post('/imports', ['file' => csvUpload(validCsv(100))])
            ->assertSessionHasErrors(['file' => 'The file may not be larger than 1 kilobytes.']);
    });

    it('rate limits uploads per user', function () {
        Queue::fake();
        config(['importer.uploads_per_minute' => 2]);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/imports', ['file' => csvUpload(validCsv())])->assertRedirect();
        $this->actingAs($user)->post('/imports', ['file' => csvUpload(validCsv())])->assertRedirect();
        $this->actingAs($user)->post('/imports', ['file' => csvUpload(validCsv())])->assertTooManyRequests();
    });
});

describe('import pages and status', function () {
    it('runs a whole import through the HTTP flow', function () {
        $user = User::factory()->create();
        $csv = validCsv(5)."202401,Nope,01,NZ,Goods,1,F\n";

        $this->actingAs($user)->post('/imports', ['file' => csvUpload($csv)]);
        $import = Import::query()->sole();

        $this->get(route('imports.show', $import))
            ->assertInertia(fn (Assert $page) => $page
                ->component('imports/show')
                ->where('import.status', 'completed_with_errors')
                ->where('import.rows_imported', 5)
                ->where('import.rows_failed', 1)
                ->where('import.can.download_errors', true)
                ->has('latestErrors', 1));

        $this->getJson(route('imports.status', $import))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('import.is_active', false)
            ->assertJsonPath('import.total_rows', 6)
            ->assertJsonPath('import.bytes_processed', strlen($csv))
            ->assertJsonPath('latest_errors.0.line_number', 7)
            ->assertJsonPath('latest_errors.0.column', 'account');
    });

    it('reports live progress from chunks while an import runs', function () {
        $user = User::factory()->create();
        $import = importOwnedBy($user, ['status' => ImportStatus::Processing, 'size_bytes' => 1_000, 'started_at' => now()->subSeconds(10)]);
        $import->chunks()->create(['sequence' => 0, 'start_offset' => 0, 'end_offset' => 1_000, 'first_record' => 1, 'next_record' => 400]);
        $import->chunks()->update(['rows_processed' => 399, 'rows_imported' => 390, 'rows_failed' => 9, 'bytes_processed' => 400]);

        $this->actingAs($user)->getJson(route('imports.status', $import))
            ->assertJsonPath('import.is_active', true)
            ->assertJsonPath('import.rows_processed', 399)
            ->assertJsonPath('import.rows_failed', 9)
            ->assertJsonPath('import.bytes_processed', 400)
            ->assertJsonPath('import.can.cancel', true)
            ->assertJsonPath('import.can.retry', false);
    });

    it('cancels an import through the endpoint', function () {
        $user = User::factory()->create();
        $import = importOwnedBy($user, ['status' => ImportStatus::Processing]);

        $this->actingAs($user)->post(route('imports.cancel', $import))->assertRedirect();

        expect($import->refresh()->status)->toBe(ImportStatus::Cancelled);
    });

    it('refuses to retry an import that has no failed chunks', function () {
        $user = User::factory()->create();
        $import = importOwnedBy($user, ['status' => ImportStatus::Completed]);

        $this->actingAs($user)->post(route('imports.retry', $import))->assertRedirect();

        expect($import->refresh()->status)->toBe(ImportStatus::Completed);
    });
});

describe('error report', function () {
    it('neutralises spreadsheet formulas in every cell', function () {
        $user = User::factory()->create();
        $import = importOwnedBy($user);

        DB::table('import_errors')->insert(collect([
            ['=HYPERLINK("http://evil.example","click")', '=1+1'],
            ['+SUM(A1:A2)', '+1'],
            ['-2+3', '-5'],
            ['@cmd', '@x'],
            ["\tTAB", "\tx"],
            ["\rCR", "\rx"],
            ['plain text', 'safe'],
        ])->map(fn (array $cells, int $i) => [
            'import_id' => $import->id,
            'line_number' => $i + 2,
            'column' => 'code',
            'message' => $cells[0],
            'raw_excerpt' => $cells[1],
        ])->all());

        $response = $this->actingAs($user)->get(route('imports.errors', $import));
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $records = iterator_to_array(Reader::createFromString($response->streamedContent())->setEscape('')->getRecords(), false);

        expect($records[0])->toBe(['line', 'column', 'message', 'raw_excerpt'])
            ->and(array_column(array_slice($records, 1), 2))->toBe([
                '\'=HYPERLINK("http://evil.example","click")',
                "'+SUM(A1:A2)",
                "'-2+3",
                "'@cmd",
                "'\tTAB",
                "'\rCR",
                'plain text',
            ])
            ->and(array_column(array_slice($records, 1), 3))->toBe(["'=1+1", "'+1", "'-5", "'@x", "'\tx", "'\rx", 'safe']);
    });

    it('orders the report by line number', function () {
        $user = User::factory()->create();
        $import = importOwnedBy($user);

        foreach ([9, 3, 5] as $line) {
            DB::table('import_errors')->insert(['import_id' => $import->id, 'line_number' => $line, 'column' => '', 'message' => 'm', 'raw_excerpt' => '']);
        }

        $content = $this->actingAs($user)->get(route('imports.errors', $import))->streamedContent();

        expect(array_map(fn ($line) => explode(',', $line)[0], array_slice(explode("\n", trim($content)), 1)))->toBe(['3', '5', '9']);
    });
});
