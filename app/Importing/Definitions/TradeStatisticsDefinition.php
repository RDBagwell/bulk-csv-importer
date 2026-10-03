<?php

namespace App\Importing\Definitions;

use App\Importing\Rules\Decimal;
use App\Importing\Rules\OneOf;
use App\Importing\Rules\Period;
use App\Importing\Rules\Text;

/**
 * Monthly trade statistics: one value per period, flow, product code and
 * partner country, in the shape of public overseas-trade datasets.
 */
final class TradeStatisticsDefinition implements ImportDefinition
{
    public function key(): string
    {
        return 'trade_statistics';
    }

    public function label(): string
    {
        return 'Trade statistics';
    }

    public function table(): string
    {
        return 'trade_statistics';
    }

    public function columns(): array
    {
        return [
            new Column('time_ref', new Period(minYear: 1900, maxYear: 2100)),
            new Column('account', new OneOf(['Exports', 'Imports'])),
            new Column('code', new Text(maxLength: 10, pattern: '/^[A-Za-z0-9]+$/', patternMessage: 'may only contain letters and digits')),
            new Column('country_code', new Text(pattern: '/^[A-Z]{2}$/', patternMessage: 'must be a 2-letter uppercase country code')),
            new Column('product_type', new OneOf(['Goods', 'Services'])),
            new Column('value', new Decimal(precision: 18, scale: 2)),
            new Column('status', new OneOf(['F', 'P', 'R'])),
        ];
    }
}
