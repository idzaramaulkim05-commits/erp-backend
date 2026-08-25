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
            ['module_key' => 'request_pppoe_noc'],
            [
                'label' => 'Request PPPoE NOC',
                'description' => 'Antrean request PPPoE dari teknisi lapangan.',
                'route_target' => '/app/request-pppoe-noc',
                'navigation_head_key' => 'operasional',
                'sort_order' => 45,
                'quick_action' => null,
                'view_formats' => ['table', 'grid'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ]
        );

        RoleModuleMapping::query()->updateOrCreate(
            ['role' => 'noc', 'module_key' => 'request_pppoe_noc'],
            ['is_visible' => true]
        );
    }

    public function down(): void
    {
        RoleModuleMapping::query()->where('module_key', 'request_pppoe_noc')->delete();
        AppNavigationModule::query()->where('module_key', 'request_pppoe_noc')->delete();
    }
};
