<?php

namespace Modules\RoadTransport\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\RoadTransport\App\Models\ExpenseCategory;

class RoadExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['ناهار', 10000000],
            ['شام', 10000000],
            ['آب و نوشیدنی', 2000000],
        ] as [$name, $limit]) {
            ExpenseCategory::firstOrCreate(['name' => $name], [
                'max_amount_rials' => $limit,
                'is_active' => true,
            ]);
        }
    }
}

