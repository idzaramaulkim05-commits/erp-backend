<?php

use App\Models\AppNavigationModule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AppNavigationModule::query()->updateOrCreate(
            ['module_key' => 'retur_gudang_perangkat'],
            [
                'label' => 'Retur Gudang Perangkat',
                'description' => 'QC retur perangkat dari teknisi setelah maintenance pergantian alat atau alat pengganti yang tidak terpakai.',
                'route_target' => '/app/retur-gudang-perangkat',
                'navigation_head_key' => 'operasional',
                'sort_order' => 16,
                'quick_action' => null,
                'view_formats' => ['table', 'grid'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ],
        );
    }

    public function down(): void
    {
        AppNavigationModule::query()
            ->where('module_key', 'retur_gudang_perangkat')
            ->delete();
    }
};
