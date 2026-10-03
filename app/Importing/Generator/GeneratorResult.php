<?php

namespace App\Importing\Generator;

final readonly class GeneratorResult
{
    /**
     * @param  array<string, int>  $invalidByKind
     */
    public function __construct(
        public string $path,
        public int $rows,
        public int $invalidRows,
        public int $bytes,
        public array $invalidByKind,
    ) {}

    public function validRows(): int
    {
        return $this->rows - $this->invalidRows;
    }
}
