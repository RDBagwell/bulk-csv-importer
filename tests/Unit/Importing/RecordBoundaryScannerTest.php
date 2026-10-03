<?php

use App\Importing\Csv\ByteRange;
use App\Importing\Csv\RangeReader;
use App\Importing\Csv\RecordBoundaryScanner;
use League\Csv\Reader;

afterEach(function () {
    foreach ($GLOBALS['scannerTestFiles'] ?? [] as $path) {
        @unlink($path);
    }

    $GLOBALS['scannerTestFiles'] = [];
});

function csvFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'scan');
    file_put_contents($path, $contents);
    $GLOBALS['scannerTestFiles'][] = $path;

    return $path;
}

/**
 * Reads every range with the production reader and concatenates the records.
 *
 * @return list<list<string|null>>
 */
function readAllRanges(string $path, array $ranges): array
{
    $records = [];

    foreach ($ranges as $range) {
        $count = 0;

        foreach ((new RangeReader($path, $range))->records() as $number => $record) {
            expect($number)->toBe($range->firstRecord + $count);
            $records[] = $record;
            $count++;
        }

        expect($count)->toBe($range->records);
    }

    return $records;
}

/**
 * The reference: parse the whole file in one go (BOM removed, as the reader does).
 *
 * @return list<list<string|null>>
 */
function parseWhole(string $contents): array
{
    $path = csvFile(str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents);

    return array_map(
        'array_values',
        iterator_to_array(Reader::createFromPath($path)->setEscape('')->includeEmptyRecords()->getRecords(), false),
    );
}

function expectRangesCover(string $path, array $ranges, int $size): void
{
    $expectedStart = 0;

    foreach ($ranges as $range) {
        expect($range->start)->toBe($expectedStart);
        expect($range->end)->toBeGreaterThan($range->start);
        $expectedStart = $range->end;
    }

    expect($expectedStart)->toBe($size);
}

it('never splits a record that contains quoted newlines', function () {
    $contents = "h1,h2\n\"a\nb\",1\n\"c\n\nd\",2\n\"e\"\"\nf\",3\n";
    $path = csvFile($contents);

    foreach ([1, 2, 5, 8, 13, 1000] as $target) {
        $result = (new RecordBoundaryScanner(readBytes: 3))->split($path, $target);

        expect($result->records)->toBe(4);
        expectRangesCover($path, $result->ranges, strlen($contents));
        expect(readAllRanges($path, $result->ranges))->toBe(parseWhole($contents));
    }
});

it('handles CRLF line endings', function () {
    $contents = "h1,h2\r\n1,2\r\n\"x\r\ny\",3\r\n4,5\r\n";
    $path = csvFile($contents);
    $result = (new RecordBoundaryScanner)->split($path, 4);

    expect($result->records)->toBe(4)
        ->and(readAllRanges($path, $result->ranges))->toBe([['h1', 'h2'], ['1', '2'], ["x\r\ny", '3'], ['4', '5']]);
});

it('skips a UTF-8 BOM, even before a quoted first field', function () {
    $contents = "\xEF\xBB\xBF\"time_ref\",account\n\"a\nb\",c\n";
    $path = csvFile($contents);
    $result = (new RecordBoundaryScanner(readBytes: 2))->split($path, 1);

    expect($result->records)->toBe(2)
        ->and(readAllRanges($path, $result->ranges))->toBe([['time_ref', 'account'], ["a\nb", 'c']]);
});

it('counts a final record without a trailing newline', function () {
    $path = csvFile("h\n1\n2");
    $result = (new RecordBoundaryScanner)->split($path, 1);

    expect($result->records)->toBe(3)
        ->and(readAllRanges($path, $result->ranges))->toBe([['h'], ['1'], ['2']]);
});

it('returns no ranges for an empty file', function () {
    $result = (new RecordBoundaryScanner)->split(csvFile(''), 10);

    expect($result->ranges)->toBe([])
        ->and($result->records)->toBe(0)
        ->and($result->bytes)->toBe(0);
});

it('treats a quote in the middle of an unquoted field as a literal', function () {
    $contents = "a,b\n12\"34,x\ny,z\n";
    $path = csvFile($contents);
    $result = (new RecordBoundaryScanner)->split($path, 1);

    expect($result->records)->toBe(3)
        ->and(readAllRanges($path, $result->ranges))->toBe(parseWhole($contents));
});

it('produces ranges close to the target size', function () {
    $contents = "h\n".str_repeat("0123456789\n", 1000);
    $path = csvFile($contents);
    $result = (new RecordBoundaryScanner)->split($path, 1000);

    expect(count($result->ranges))->toBe(11);

    foreach (array_slice($result->ranges, 0, -1) as $range) {
        expect($range->length())->toBeGreaterThanOrEqual(1000)->toBeLessThan(1011);
    }
});

it('agrees with a whole-file parse on random nasty input', function () {
    mt_srand(1234);
    $pieces = ['a', 'bb', ' ', '"', '""', ',', "\n", "\r\n", 'x"y', "\"q,\n\"", "\t", 'é', ' "s"', '"un'];

    for ($i = 0; $i < 400; $i++) {
        $contents = $i % 5 === 0 ? "\xEF\xBB\xBF" : '';

        for ($n = mt_rand(1, 30); $n > 0; $n--) {
            $contents .= $pieces[mt_rand(0, count($pieces) - 1)];
        }

        $path = csvFile($contents);
        $expected = parseWhole($contents);

        foreach ([1, 3, 7, 64] as $target) {
            $result = (new RecordBoundaryScanner(readBytes: 4))->split($path, $target);

            expect(readAllRanges($path, $result->ranges))->toBe($expected, json_encode($contents))
                ->and($result->records)->toBe(count($expected));
        }
    }
});

it('reads a range from its own start and stops at its end', function () {
    $path = csvFile("h\none\ntwo\nthree\n");
    $range = new ByteRange(6, 10, firstRecord: 3, records: 1);

    expect(iterator_to_array((new RangeReader($path, $range))->records()))->toBe([3 => ['two']]);
});
