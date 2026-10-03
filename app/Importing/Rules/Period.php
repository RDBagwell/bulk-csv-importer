<?php

namespace App\Importing\Rules;

/**
 * A reference period in YYYYMM form with a month from 01 to 12.
 */
final readonly class Period implements Rule
{
    public function __construct(
        private int $minYear = 1900,
        private int $maxYear = 2100,
    ) {}

    public function apply(string $value): mixed
    {
        if (strlen($value) !== 6 || ! ctype_digit($value)) {
            return new Invalid('must be a period in YYYYMM format');
        }

        $year = (int) substr($value, 0, 4);
        $month = (int) substr($value, 4, 2);

        if ($month < 1 || $month > 12) {
            return new Invalid('month must be between 01 and 12');
        }

        if ($year < $this->minYear || $year > $this->maxYear) {
            return new Invalid("year must be between {$this->minYear} and {$this->maxYear}");
        }

        return $value;
    }
}
