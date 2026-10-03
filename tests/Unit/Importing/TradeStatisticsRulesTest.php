<?php

use App\Importing\Definitions\Column;
use App\Importing\Definitions\TradeStatisticsDefinition;
use App\Importing\Rules\Invalid;

function column(string $name): Column
{
    foreach ((new TradeStatisticsDefinition)->columns() as $column) {
        if ($column->header === $name) {
            return $column;
        }
    }

    throw new RuntimeException("No column {$name}");
}

function normalized(string $column, ?string $value): mixed
{
    $result = column($column)->normalize($value);

    return $result instanceof Invalid ? 'invalid: '.$result->message : $result;
}

it('validates time_ref as YYYYMM with a real month', function (string $value, string $expected) {
    expect(normalized('time_ref', $value))->toBe($expected);
})->with([
    ['202401', '202401'],
    ['202412', '202412'],
    [' 202403 ', '202403'],
    ['202400', 'invalid: month must be between 01 and 12'],
    ['202413', 'invalid: month must be between 01 and 12'],
    ['2024-01', 'invalid: must be a period in YYYYMM format'],
    ['20241', 'invalid: must be a period in YYYYMM format'],
    ['abcdef', 'invalid: must be a period in YYYYMM format'],
    ['180001', 'invalid: year must be between 1900 and 2100'],
]);

it('accepts only the known accounts, product types and statuses, case-sensitively', function (string $column, string $value, bool $valid) {
    expect(column($column)->normalize($value) instanceof Invalid)->toBe(! $valid);
})->with([
    ['account', 'Exports', true],
    ['account', 'Imports', true],
    ['account', 'exports', false],
    ['account', 'Exprts', false],
    ['product_type', 'Goods', true],
    ['product_type', 'Services', true],
    ['product_type', 'Things', false],
    ['status', 'F', true],
    ['status', 'P', true],
    ['status', 'R', true],
    ['status', 'X', false],
    ['status', 'f', false],
]);

it('validates code length and characters', function (string $value, bool $valid) {
    expect(column('code')->normalize($value) instanceof Invalid)->toBe(! $valid);
})->with([
    ['01', true],
    ['A12', true],
    ['ABCDEFGHIJ', true],
    ['ABCDEFGHIJK', false],
    ['AB-12', false],
    ["AB\n12", false],
]);

it('requires a two-letter uppercase country code', function (string $value, bool $valid) {
    expect(column('country_code')->normalize($value) instanceof Invalid)->toBe(! $valid);
})->with([
    ['NZ', true],
    ['nz', false],
    ['NZL', false],
    ['N', false],
]);

it('keeps decimal values as exact strings', function (string $value, string $expected) {
    expect(normalized('value', $value))->toBe($expected);
})->with([
    ['1234.56', '1234.56'],
    ['0.1', '0.1'],
    ['-12.5', '-12.5'],
    ['9999999999999999.99', '9999999999999999.99'],
    ['12.345', 'invalid: must have at most 16 digits before and 2 after the decimal point'],
    ['99999999999999999', 'invalid: must have at most 16 digits before and 2 after the decimal point'],
    ['N/A', 'invalid: must be a decimal number'],
    ['1e5', 'invalid: must be a decimal number'],
    ['1,000', 'invalid: must be a decimal number'],
]);

it('reports blank required values', function () {
    expect(normalized('value', ''))->toBe('invalid: is required')
        ->and(normalized('status', '   '))->toBe('invalid: is required')
        ->and(normalized('account', null))->toBe('invalid: is required');
});
