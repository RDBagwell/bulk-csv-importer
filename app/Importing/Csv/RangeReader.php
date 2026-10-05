<?php

namespace App\Importing\Csv;

use Generator;
use League\Csv\Reader;
use RuntimeException;

/**
 * Streams the records of one byte range with league/csv.
 *
 * Records are keyed by their 1-based record number in the whole file, so
 * error line numbers are the same whichever range a record falls in.
 * Memory use is bounded by the parser's read buffer, not the range size.
 */
final class RangeReader
{
    /** @var resource|null */
    private $stream;

    public function __construct(
        private readonly string $path,
        private readonly ByteRange $range,
    ) {
        ByteRangeStream::register();
    }

    /**
     * @return Generator<int, list<string|null>>
     */
    public function records(int $fromRecord = 1): Generator
    {
        $stream = @fopen(ByteRangeStream::uri($this->path, $this->range->start, $this->range->end), 'rb');

        if ($stream === false) {
            throw new RuntimeException('Unable to open the import file.');
        }

        $this->stream = $stream;

        $reader = Reader::from($stream)
            ->setDelimiter(',')
            ->setEnclosure('"')
            ->setEscape('')
            ->includeEmptyRecords();

        try {
            foreach ($reader->getRecords() as $offset => $record) {
                $number = $this->range->firstRecord + $offset;

                if ($number >= $fromRecord) {
                    yield $number => array_values($record);
                }
            }
        } finally {
            $this->stream = null;
            unset($reader);
            fclose($stream);
        }
    }

    /**
     * Bytes of the range consumed by the parser so far (it reads ahead, so
     * this runs slightly ahead of the current record). Used for progress.
     */
    public function bytesRead(): int
    {
        if (! is_resource($this->stream)) {
            return 0;
        }

        return (int) ftell($this->stream);
    }
}
