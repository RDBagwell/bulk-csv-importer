<?php

namespace App\Importing\Definitions;

/**
 * Describes one importable dataset.
 *
 * The pipeline only talks to this interface, so a new dataset is a new
 * implementation (plus its table migration) registered in config/importer.php.
 */
interface ImportDefinition
{
    /** Stable identifier stored on each import. */
    public function key(): string;

    public function label(): string;

    /** Table that valid rows are written to. It must have import_id and line_number columns with a unique key over them. */
    public function table(): string;

    /** @return list<Column> */
    public function columns(): array;
}
