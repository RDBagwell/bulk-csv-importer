<?php

namespace App\Importing\Csv;

use InvalidArgumentException;
use RuntimeException;

/**
 * Splits a CSV file into byte ranges that each start and end on a record
 * boundary, in one streaming pass.
 *
 * Seeking to an offset and looking for the next newline is not enough:
 * a quoted field may contain newlines, and only the quote state from the
 * start of the file tells you whether a given newline ends a record. This
 * scanner tracks that state with the same rules PHP's CSV parser (and so
 * league/csv) applies with an empty escape character:
 *
 *  - an enclosure opens a quoted field only at the start of a field,
 *    optionally after spaces or tabs;
 *  - inside a quoted field a doubled enclosure is a literal quote and a
 *    single one closes the field;
 *  - an enclosure anywhere else is an ordinary character;
 *  - outside quotes, "\n" ends a record ("\r\n" works because the "\r"
 *    is part of the last field, which the parser trims);
 *  - a UTF-8 BOM at the very start is skipped.
 *
 * Only quotes are inspected byte by byte. Runs of unquoted data are handled
 * with strpos/substr_count, so a typical file is scanned at close to disk
 * speed. The scan also yields the exact record count for free.
 */
final class RecordBoundaryScanner
{
    private const string BOM = "\xEF\xBB\xBF";

    /**
     * @param  int<2, max>  $readBytes
     */
    public function __construct(
        private readonly int $readBytes = 1 << 20,
        private readonly string $delimiter = ',',
        private readonly string $enclosure = '"',
    ) {
        if ($readBytes < 2) {
            throw new InvalidArgumentException('The read buffer must be at least 2 bytes.');
        }
    }

    /**
     * @param  int  $targetBytes  Preferred range size; each range ends at the first record boundary at or after it.
     */
    public function split(string $path, int $targetBytes): SplitResult
    {
        if ($targetBytes < 1) {
            throw new InvalidArgumentException('The target range size must be positive.');
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the file for scanning.');
        }

        try {
            return $this->scan($handle, $targetBytes);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function scan($handle, int $targetBytes): SplitResult
    {
        $q = $this->enclosure;
        $ranges = [];
        $records = 0;           // record terminators seen so far
        $rangeStart = 0;
        $rangeFirstRecord = 1;
        $inQuotes = false;
        $pendingQuote = false;  // an enclosure inside quotes was the last byte of the previous buffer
        $atFieldStart = true;   // only blanks since the last delimiter / record start
        $lastByte = '';

        // Skip a UTF-8 BOM; the reader hides it from the parser too, so the
        // first field starts right after it.
        $contentStart = fread($handle, strlen(self::BOM)) === self::BOM ? strlen(self::BOM) : 0;
        fseek($handle, $contentStart);
        $offset = $contentStart; // absolute offset of the next buffer read

        while (($buffer = fread($handle, $this->readBytes)) !== false && $buffer !== '') {
            $length = strlen($buffer);
            $pos = 0;

            if ($pendingQuote) {
                $pendingQuote = false;

                if ($buffer[0] === $q) {
                    $pos = 1;           // doubled enclosure: still inside the quoted field
                } else {
                    $inQuotes = false;  // the previous enclosure closed the field
                    $atFieldStart = false;
                }
            }

            while ($pos < $length) {
                if ($inQuotes) {
                    $quote = strpos($buffer, $q, $pos);

                    if ($quote === false) {
                        break;
                    }

                    if ($quote + 1 === $length) {
                        $pendingQuote = true;
                        break;
                    }

                    if ($buffer[$quote + 1] === $q) {
                        $pos = $quote + 2;

                        continue;
                    }

                    $inQuotes = false;
                    $atFieldStart = false;
                    $pos = $quote + 1;

                    continue;
                }

                $quote = strpos($buffer, $q, $pos);
                $segmentEnd = $quote === false ? $length : $quote;

                if ($segmentEnd > $pos) {
                    $this->countRecordEnds($buffer, $pos, $segmentEnd, $offset, $targetBytes, $records, $rangeStart, $rangeFirstRecord, $ranges);
                    $atFieldStart = $this->endsAtFieldStart($buffer, $pos, $segmentEnd, $atFieldStart);
                }

                if ($quote === false) {
                    break;
                }

                if ($atFieldStart) {
                    $inQuotes = true;
                } else {
                    $atFieldStart = false;
                }

                $pos = $quote + 1;
            }

            $lastByte = $buffer[$length - 1];
            $offset += $length;
        }

        $size = $offset;

        // A final record without a trailing newline (or an unterminated
        // quoted field, which the parser reads to the end of the file).
        if ($size > $contentStart && ($inQuotes || $pendingQuote || $lastByte !== "\n")) {
            $records++;
        }

        if ($size > $rangeStart && $records >= $rangeFirstRecord) {
            $ranges[] = new ByteRange($rangeStart, $size, $rangeFirstRecord, $records - $rangeFirstRecord + 1);
        }

        return new SplitResult($ranges, $records, $size);
    }

    /**
     * Counts the newlines in an unquoted segment, closing a range at the
     * first record boundary past each target.
     *
     * @param  list<ByteRange>  $ranges
     */
    private function countRecordEnds(
        string $buffer,
        int $from,
        int $to,
        int $offset,
        int $targetBytes,
        int &$records,
        int &$rangeStart,
        int &$rangeFirstRecord,
        array &$ranges,
    ): void {
        while (true) {
            // A newline at or after this local index makes the current range at least $targetBytes long.
            $threshold = max($from, $rangeStart + $targetBytes - 1 - $offset);
            $newline = $threshold < $to ? strpos($buffer, "\n", $threshold) : false;

            if ($newline === false || $newline >= $to) {
                $records += substr_count($buffer, "\n", $from, $to - $from);

                return;
            }

            $records += substr_count($buffer, "\n", $from, $newline + 1 - $from);
            $end = $offset + $newline + 1;
            $ranges[] = new ByteRange($rangeStart, $end, $rangeFirstRecord, $records - $rangeFirstRecord + 1);
            $rangeStart = $end;
            $rangeFirstRecord = $records + 1;
            $from = $newline + 1;
        }
    }

    /**
     * Whether the position just after $buffer[$from..$to) is still at the
     * start of a field: walk back over blanks to the previous delimiter or
     * newline, falling back to the state at $from.
     */
    private function endsAtFieldStart(string $buffer, int $from, int $to, bool $stateAtFrom): bool
    {
        $i = $to - 1;

        while ($i >= $from && ($buffer[$i] === ' ' || $buffer[$i] === "\t")) {
            $i--;
        }

        if ($i < $from) {
            return $stateAtFrom;
        }

        return $buffer[$i] === $this->delimiter || $buffer[$i] === "\n";
    }
}
