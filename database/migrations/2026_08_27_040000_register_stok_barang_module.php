<?php

use App\Models\AppNavigationModule;
use App\Models\NavigationHead;
use App\Models\RoleModuleMapping;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        NavigationHead::query()->updateOrCreate(
            ['key' => 'operasional'],
            ['label' => 'Operasional', 'sort_order' => 1, 'is_active' => true]
        );

        AppNavigationModule::query()->updateOrCreate(
            ['module_key' => 'stok_barang'],
            [
                'label' => 'Stok Barang & Material',
                'description' => 'Modul inventaris dan stok barang gudang, material lapangan, dan monitoring stok kritis.',
                'route_target' => '/app/stok-barang',
                'navigation_head_key' => 'operasional',
                'sort_order' => 50,
                'quick_action' => 'new_procurement',
                'view_formats' => ['table', 'grid'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ]
        );

        foreach (['inventory', 'superadmin', 'lead_tech', 'management', 'finance'] as $role) {
            RoleModuleMapping::query()->updateOrCreate(
                ['role' => $role, 'module_key' => 'stok_barang'],
                ['is_visible' => true]
            );
        }
    }

    public function down(): void
    {
        RoleModuleMapping::query()->where('module_key', 'stok_barang')->delete();
        AppNavigationModule::query()->where('module_key', 'stok_barang')->delete();
    }
};
