<?php

namespace App\Importing\Rules;

/**
 * A string with an optional maximum length (in characters) and pattern.
 */
final readonly class Text implements Rule
{
    public function __construct(
        private ?int $maxLength = null,
        private ?string $pattern = null,
        private string $patternMessage = 'has an invalid format',
    ) {}

    public function apply(string $value): mixed
    {
        if ($this->maxLength !== null && mb_strlen($value) > $this->maxLength) {
            return new Invalid("may not be longer than {$this->maxLength} characters");
        }

        if ($this->pattern !== null && preg_match($this->pattern, $value) !== 1) {
            return new Invalid($this->patternMessage);
        }

        return $value;
    }
}
