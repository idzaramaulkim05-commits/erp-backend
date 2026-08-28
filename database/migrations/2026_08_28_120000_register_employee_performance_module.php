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
            ['key' => 'dashboards'],
            ['label' => 'Dashboards', 'sort_order' => 1, 'is_active' => true]
        );

        AppNavigationModule::query()->updateOrCreate(
            ['module_key' => 'performa_karyawan'],
            [
                'label' => 'Lihat Performa Karyawan',
                'description' => 'Monitoring KPI, produktivitas, beban kerja, dan rekam jejak penyelesaian tugas karyawan.',
                'route_target' => '/app/performa-karyawan',
                'navigation_head_key' => 'dashboards',
                'sort_order' => 15,
                'quick_action' => null,
                'view_formats' => ['grid', 'table'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ]
        );

        foreach (['management', 'superadmin'] as $role) {
            RoleModuleMapping::query()->updateOrCreate(
                ['role' => $role, 'module_key' => 'performa_karyawan'],
                ['is_visible' => true]
            );
        }
    }

    public function down(): void
    {
        RoleModuleMapping::query()->where('module_key', 'performa_karyawan')->delete();
        AppNavigationModule::query()->where('module_key', 'performa_karyawan')->delete();
    }
};
