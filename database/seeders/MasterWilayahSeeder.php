<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MasterWilayahSeeder extends Seeder
{
    /**
     * Run the database seeds for all 15 Kabupaten & Kota in Provinsi Lampung (18).
     * Populates all 2,642+ official villages across all 228 districts.
     */
    public function run(): void
    {
        $jsonFile = __DIR__ . '/lampung_wilayah.json';

        if (file_exists($jsonFile)) {
            $data = json_decode(file_get_contents($jsonFile), true);
            if (!empty($data)) {
                DB::table('master_wilayah')->truncate();
                $chunks = array_chunk($data, 300);
                foreach ($chunks as $chunk) {
                    DB::table('master_wilayah')->insert($chunk);
                }
                return;
            }
        }
    }
}
