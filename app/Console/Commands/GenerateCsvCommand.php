<?php

namespace App\Console\Commands;

use App\Importing\Generator\TradeCsvGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('importer:generate
    {rows : Number of data rows to write}
    {--errors=0 : Percentage of rows to corrupt (0–100)}
    {--quoted-newlines : Include multi-line quoted fields to exercise the splitter}
    {--seed=42 : Random seed; the same seed always produces the same file}
    {--out= : Output path (defaults to storage/app/generated/trade-{rows}.csv)}')]
#[Description('Stream a synthetic trade-statistics CSV to disk')]
class GenerateCsvCommand extends Command
{
    public function handle(): int
    {
        $rows = (int) $this->argument('rows');
        $errors = (float) $this->option('errors');
        $path = $this->option('out') ?: storage_path("app/generated/trade-{$rows}.csv");

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $started = hrtime(true);
        $result = (new TradeCsvGenerator((int) $this->option('seed')))
            ->generate($path, $rows, $errors, (bool) $this->option('quoted-newlines'));
        $seconds = (hrtime(true) - $started) / 1e9;

        $this->components->info("Wrote {$result->path}");
        $this->components->twoColumnDetail('Data rows', number_format($result->rows));
        $this->components->twoColumnDetail('Expected valid rows', number_format($result->validRows()));
        $this->components->twoColumnDetail('Expected invalid rows', number_format($result->invalidRows));

        foreach ($result->invalidByKind as $kind => $count) {
            $this->components->twoColumnDetail("  {$kind}", number_format($count));
        }

        $this->components->twoColumnDetail('Size', number_format($result->bytes / 1_048_576, 1).' MiB');
        $this->components->twoColumnDetail('Time', number_format($seconds, 2).' s');
        $this->components->twoColumnDetail('Peak memory', number_format(memory_get_peak_usage(true) / 1_048_576, 1).' MiB');

        return self::SUCCESS;
    }
}
