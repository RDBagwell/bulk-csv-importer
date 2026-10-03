<?php

namespace App\Importing\Csv;

final readonly class SplitResult
{
    /**
     * @param  list<ByteRange>  $ranges
     * @param  int  $records  Total records in the file, including the header.
     */
    public function __construct(
        public array $ranges,
        public int $records,
        public int $bytes,
    ) {}
}
