<?php

namespace Database\Factories;

use App\Enums\ImportMode;
use App\Models\Import;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'definition' => 'trade_statistics',
            'original_filename' => 'trade.csv',
            'stored_path' => Str::uuid().'.csv',
            'size_bytes' => 1024,
            'mode' => ImportMode::Sequential,
            'column_map' => [
                'time_ref' => 0, 'account' => 1, 'code' => 2, 'country_code' => 3,
                'product_type' => 4, 'value' => 5, 'status' => 6,
            ],
            'field_count' => 7,
        ];
    }
}
