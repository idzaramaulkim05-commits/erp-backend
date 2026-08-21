<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->environment(['local', 'testing']) || (bool) env('APP_ENABLE_DEMO_SEED', false)) {
            $this->call(IomsDemoSeeder::class);
        }
    }
}
