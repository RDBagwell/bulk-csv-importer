<?php

namespace App\Importing\Csv;

/**
 * How a file's header row lines up with a definition's columns.
 */
final readonly class HeaderMapping
{
    /**
     * @param  array<string, int>  $positions  Column attribute => zero-based field position.
     * @param  list<string>  $missing  Required headers not present.
     * @param  list<string>  $unknown  Headers the definition does not know.
     * @param  list<string>  $duplicates  Headers that appear more than once.
     */
    public function __construct(
        public array $positions,
        public int $fieldCount,
        public array $missing = [],
        public array $unknown = [],
        public array $duplicates = [],
        public bool $empty = false,
    ) {}

    public function isValid(): bool
    {
        return ! $this->empty && $this->missing === [] && $this->unknown === [] && $this->duplicates === [];
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        if ($this->empty) {
            return ['The file is empty or has no header row.'];
        }

        $problems = [];

        if ($this->missing !== []) {
            $problems[] = 'Missing required columns: '.implode(', ', $this->missing).'.';
        }

        if ($this->unknown !== []) {
            $problems[] = 'Unknown columns: '.implode(', ', $this->unknown).'.';
        }

        if ($this->duplicates !== []) {
            $problems[] = 'Duplicate columns: '.implode(', ', $this->duplicates).'.';
        }

        return $problems;
    }
}
