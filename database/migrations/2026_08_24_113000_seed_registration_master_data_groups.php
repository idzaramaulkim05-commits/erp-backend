<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('admin_master_data_groups')->updateOrInsert(
            ['key' => 'regions'],
            [
                'label' => 'Wilayah',
                'items' => json_encode([
                    ['name' => 'Sidoarjo Kota'],
                    ['name' => 'Waru'],
                    ['name' => 'Krian'],
                    ['name' => 'Gedangan'],
                ]),
                'editable_fields' => json_encode(['name']),
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        DB::table('admin_master_data_groups')->updateOrInsert(
            ['key' => 'service_packages'],
            [
                'label' => 'Paket Layanan',
                'items' => json_encode([
                    ['name' => 'Home 20 Mbps', 'monthlyFee' => 185000],
                    ['name' => 'Home 50 Mbps', 'monthlyFee' => 285000],
                    ['name' => 'Gamer 100 Mbps', 'monthlyFee' => 425000],
                    ['name' => 'Business 200 Mbps', 'monthlyFee' => 825000],
                ]),
                'editable_fields' => json_encode(['name', 'monthlyFee']),
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        DB::table('admin_master_data_groups')
            ->whereIn('key', ['regions', 'service_packages'])
            ->delete();
    }
};
