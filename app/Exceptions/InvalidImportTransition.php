<?php

namespace App\Exceptions;

use App\Enums\ImportStatus;
use LogicException;

final class InvalidImportTransition extends LogicException
{
    public static function between(ImportStatus $from, ImportStatus $to): self
    {
        return new self("An import cannot move from [{$from->value}] to [{$to->value}].");
    }
}
