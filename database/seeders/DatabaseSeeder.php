<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // سایر seeders
        // $this->call(UsersTableSeeder::class);

        // Seeder جدید

        $this->call(\Modules\RoadTransport\Database\Seeders\RoadExpenseCategorySeeder::class);
    }
}
