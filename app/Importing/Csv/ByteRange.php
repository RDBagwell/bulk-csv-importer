<?php

namespace App\Importing\Csv;

/**
 * A slice of a CSV file that starts and ends on record boundaries.
 */
final readonly class ByteRange
{
    /**
     * @param  int  $start  Offset of the first byte (inclusive).
     * @param  int  $end  Offset just past the last byte (exclusive).
     * @param  int  $firstRecord  1-based number of the first record in the range (the header is record 1).
     * @param  int|null  $records  Number of records in the range, when known.
     */
    public function __construct(
        public int $start,
        public int $end,
        public int $firstRecord,
        public ?int $records = null,
    ) {}

    public function length(): int
    {
        return $this->end - $this->start;
    }
}
