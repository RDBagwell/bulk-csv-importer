<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Models\ImportError;
use Illuminate\Support\Facades\Gate;
use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the stored errors as CSV: line, column, message, raw excerpt.
 *
 * Every cell goes through league/csv's EscapeFormula, which prefixes cells
 * starting with =, +, -, @, tab or carriage return with a single quote, so
 * a spreadsheet opening the report never evaluates file contents as a
 * formula (CSV / formula injection).
 */
class DownloadImportErrorsController extends Controller
{
    public function __invoke(Import $import): StreamedResponse
    {
        Gate::authorize('downloadErrors', $import);

        return response()->streamDownload(function () use ($import): void {
            $formula = new EscapeFormula;
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            $writer = Writer::from($output);
            $writer->addFormatter($formula->escapeRecord(...));
            $writer->insertOne(['line', 'column', 'message', 'raw_excerpt']);

            $import->errors()
                ->orderBy('line_number')
                ->orderBy('column')
                ->lazy(1000)
                ->each(function (ImportError $error) use ($writer): void {
                    $writer->insertOne([$error->line_number, $error->column, $error->message, $error->raw_excerpt]);
                });
        }, "import-{$import->id}-errors.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
