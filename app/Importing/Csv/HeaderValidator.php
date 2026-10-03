<?php

namespace App\Importing\Csv;

use App\Importing\Definitions\ImportDefinition;

/**
 * Reads only the first record of a file and maps it onto a definition.
 *
 * Matching ignores case, surrounding whitespace and column order. The
 * header is used to find positions only; header text is never used as an
 * attribute name, so a crafted header cannot target arbitrary columns.
 */
final class HeaderValidator
{
    private const int MAX_HEADER_DISPLAY_LENGTH = 64;

    public function validate(string $path, ImportDefinition $definition): HeaderMapping
    {
        $size = @filesize($path);
        $header = [];

        if ($size > 0) {
            foreach ((new RangeReader($path, new ByteRange(0, $size, 1)))->records() as $record) {
                $header = $record;
                break;
            }
        }

        if ($header === [] || $header === [null] || $header === ['']) {
            return new HeaderMapping([], 0, empty: true);
        }

        return $this->map($header, $definition);
    }

    /**
     * @param  list<string|null>  $header
     */
    public function map(array $header, ImportDefinition $definition): HeaderMapping
    {
        $wanted = [];

        foreach ($definition->columns() as $column) {
            $wanted[$this->normalize($column->header)] = $column;
        }

        $positions = [];
        $seen = [];
        $unknown = [];
        $duplicates = [];

        foreach ($header as $position => $name) {
            $key = $this->normalize((string) $name);

            if (isset($seen[$key])) {
                $duplicates[] = $this->display($name);

                continue;
            }

            $seen[$key] = true;

            if (isset($wanted[$key])) {
                $positions[$wanted[$key]->attribute] = $position;
            } else {
                $unknown[] = $this->display($name);
            }
        }

        $missing = [];

        foreach ($wanted as $key => $column) {
            if ($column->required && ! isset($positions[$column->attribute])) {
                $missing[] = $column->header;
            }
        }

        return new HeaderMapping($positions, count($header), $missing, $unknown, array_values(array_unique($duplicates)));
    }

    private function normalize(string $name): string
    {
        return strtolower(trim($name));
    }

    /**
     * Header text is user input that ends up in messages, so keep it short.
     */
    private function display(?string $name): string
    {
        $name = trim((string) preg_replace('/[^\P{C}]+/u', '?', mb_scrub((string) $name, 'UTF-8')));

        if ($name === '') {
            return '(blank)';
        }

        return mb_strimwidth($name, 0, self::MAX_HEADER_DISPLAY_LENGTH, '…');
    }
}
