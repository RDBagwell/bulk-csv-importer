<?php

use App\Importing\Csv\HeaderMapping;
use App\Importing\Csv\HeaderValidator;
use App\Importing\Definitions\TradeStatisticsDefinition;

function mapHeader(array $header): HeaderMapping
{
    return (new HeaderValidator)->map($header, new TradeStatisticsDefinition);
}

it('maps columns by name regardless of order and case', function () {
    $mapping = mapHeader(['STATUS', 'value', ' product_type ', 'country_code', 'code', 'Account', 'time_ref']);

    expect($mapping->isValid())->toBeTrue()
        ->and($mapping->positions)->toBe([
            'status' => 0, 'value' => 1, 'product_type' => 2, 'country_code' => 3,
            'code' => 4, 'account' => 5, 'time_ref' => 6,
        ])
        ->and($mapping->fieldCount)->toBe(7);
});

it('reports missing required columns', function () {
    $mapping = mapHeader(['time_ref', 'account', 'code']);

    expect($mapping->isValid())->toBeFalse()
        ->and($mapping->missing)->toBe(['country_code', 'product_type', 'value', 'status'])
        ->and($mapping->problems())->toBe(['Missing required columns: country_code, product_type, value, status.']);
});

it('reports unknown columns, including attempts to set internal attributes', function () {
    $mapping = mapHeader(['time_ref', 'account', 'code', 'country_code', 'product_type', 'value', 'status', 'id', 'import_id', 'created_at']);

    expect($mapping->isValid())->toBeFalse()
        ->and($mapping->unknown)->toBe(['id', 'import_id', 'created_at'])
        ->and($mapping->positions)->not->toHaveKeys(['id', 'import_id', 'created_at']);
});

it('reports duplicate columns', function () {
    $mapping = mapHeader(['time_ref', 'account', 'code', 'country_code', 'product_type', 'value', 'status', 'Value']);

    expect($mapping->isValid())->toBeFalse()
        ->and($mapping->duplicates)->toBe(['Value']);
});

it('shortens absurdly long header names in messages', function () {
    $mapping = mapHeader([str_repeat('x', 500)]);

    expect(mb_strlen($mapping->unknown[0]))->toBeLessThanOrEqual(64);
});

it('rejects an empty file', function () {
    $path = tempnam(sys_get_temp_dir(), 'hdr');

    $mapping = (new HeaderValidator)->validate($path, new TradeStatisticsDefinition);
    unlink($path);

    expect($mapping->isValid())->toBeFalse()
        ->and($mapping->problems())->toBe(['The file is empty or has no header row.']);
});
