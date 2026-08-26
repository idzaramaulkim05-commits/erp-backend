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
            ['module_key' => 'buat_tiket'],
            [
                'label' => 'Buat Tiket',
                'description' => 'Input tiket gangguan dan aduan pelanggan baru.',
                'route_target' => '/app/buat-tiket',
                'navigation_head_key' => 'operasional',
                'sort_order' => 48,
                'quick_action' => 'new_ticket',
                'view_formats' => ['grid', 'table'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ]
        );

        foreach (['helpdesk', 'superadmin', 'noc', 'sales', 'management'] as $role) {
            RoleModuleMapping::query()->updateOrCreate(
                ['role' => $role, 'module_key' => 'buat_tiket'],
                ['is_visible' => true]
            );
        }
    }

    public function down(): void
    {
        RoleModuleMapping::query()->where('module_key', 'buat_tiket')->delete();
        AppNavigationModule::query()->where('module_key', 'buat_tiket')->delete();
    }
};
