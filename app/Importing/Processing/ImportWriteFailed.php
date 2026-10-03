<?php

namespace App\Importing\Processing;

use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Replaces a QueryException from a bulk write. The original carries the
 * SQL and its bindings, i.e. row contents, which must never reach logs or
 * failed_jobs; only the SQLSTATE and driver code are kept.
 */
final class ImportWriteFailed extends RuntimeException
{
    public static function from(QueryException $e, string $table): self
    {
        $driverCode = $e->errorInfo[1] ?? null;

        return new self(sprintf(
            'Bulk write to [%s] failed (SQLSTATE %s%s).',
            $table,
            $e->getCode(),
            $driverCode !== null ? ", driver code {$driverCode}" : '',
        ));
    }
}
