<?php

namespace App\Importing\Rules;

/**
 * A fixed-point number that fits DECIMAL(precision, scale).
 *
 * The value stays a string all the way to the database so it is never
 * rounded through a float.
 */
final readonly class Decimal implements Rule
{
    private string $pattern;

    public function __construct(
        private int $precision,
        private int $scale,
        private bool $allowNegative = true,
    ) {
        $integerDigits = $precision - $scale;
        $sign = $allowNegative ? '[+-]?' : '\\+?';
        $fraction = $scale > 0 ? "(?:\\.\\d{1,{$scale}})?" : '';

        $this->pattern = "/^{$sign}\\d{1,{$integerDigits}}{$fraction}$/";
    }

    public function apply(string $value): mixed
    {
        if (preg_match($this->pattern, $value) === 1) {
            return $value;
        }

        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)$/', $value) !== 1) {
            return new Invalid('must be a decimal number');
        }

        if (! $this->allowNegative && $value[0] === '-') {
            return new Invalid('may not be negative');
        }

        return new Invalid(sprintf(
            'must have at most %d digits before and %d after the decimal point',
            $this->precision - $this->scale,
            $this->scale,
        ));
    }
}
