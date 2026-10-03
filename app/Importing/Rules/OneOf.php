<?php

namespace App\Importing\Rules;

/**
 * An exact, case-sensitive match against a fixed set of values.
 */
final readonly class OneOf implements Rule
{
    /** @var array<string, true> */
    private array $allowed;

    private string $message;

    /**
     * @param  list<string>  $values
     */
    public function __construct(array $values)
    {
        $this->allowed = array_fill_keys($values, true);
        $this->message = 'must be one of: '.implode(', ', $values);
    }

    public function apply(string $value): mixed
    {
        return isset($this->allowed[$value]) ? $value : new Invalid($this->message);
    }
}
