<?php

namespace App\Importing\Definitions;

use App\Importing\Rules\Invalid;
use App\Importing\Rules\Rule;

/**
 * One CSV column: the header it is matched by, the table column it is
 * written to, and the rule its values must pass.
 */
final readonly class Column
{
    public string $attribute;

    public function __construct(
        public string $header,
        public Rule $rule,
        ?string $attribute = null,
        public bool $required = true,
    ) {
        $this->attribute = $attribute ?? $header;
    }

    /**
     * Trims the raw cell, applies the rule and returns the value to store
     * or an Invalid.
     */
    public function normalize(?string $raw): mixed
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return $this->required ? new Invalid('is required') : null;
        }

        return $this->rule->apply($value);
    }
}
