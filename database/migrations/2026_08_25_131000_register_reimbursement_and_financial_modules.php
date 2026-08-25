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
            ['key' => 'keuangan'],
            ['label' => 'Keuangan', 'sort_order' => 6, 'is_active' => true]
        );

        $modules = [
            [
                'module_key' => 'request_rembes',
                'label' => 'Request Rembes',
                'description' => 'Pengajuan rembes pegawai.',
                'route_target' => '/app/request-rembes',
                'navigation_head_key' => 'keuangan',
                'sort_order' => 10,
                'quick_action' => null,
                'view_formats' => ['table', 'grid'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key' => 'approval_rembes_finance',
                'label' => 'Approval Rembes Finance',
                'description' => 'Review, approval, dan pencairan rembes.',
                'route_target' => '/app/approval-rembes-finance',
                'navigation_head_key' => 'keuangan',
                'sort_order' => 11,
                'quick_action' => null,
                'view_formats' => ['table', 'grid'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key' => 'laporan_keuangan',
                'label' => 'Laporan Keuangan',
                'description' => 'Ledger billing, rembes, dan mutasi.',
                'route_target' => '/app/laporan-keuangan',
                'navigation_head_key' => 'keuangan',
                'sort_order' => 12,
                'quick_action' => null,
                'view_formats' => ['table', 'grid'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ],
        ];

        foreach ($modules as $module) {
            AppNavigationModule::query()->updateOrCreate(
                ['module_key' => $module['module_key']],
                $module,
            );
        }

        $requestRoles = ['management', 'sales', 'noc', 'helpdesk', 'lead_tech', 'field_tech', 'finance', 'inventory'];
        foreach ($requestRoles as $role) {
            RoleModuleMapping::query()->updateOrCreate(
                ['role' => $role, 'module_key' => 'request_rembes'],
                ['is_visible' => true]
            );
        }

        foreach (['finance', 'management'] as $role) {
            RoleModuleMapping::query()->updateOrCreate(
                ['role' => $role, 'module_key' => 'approval_rembes_finance'],
                ['is_visible' => true]
            );
            RoleModuleMapping::query()->updateOrCreate(
                ['role' => $role, 'module_key' => 'laporan_keuangan'],
                ['is_visible' => true]
            );
        }
    }

    public function down(): void
    {
        RoleModuleMapping::query()->whereIn('module_key', [
            'request_rembes',
            'approval_rembes_finance',
            'laporan_keuangan',
        ])->delete();

        AppNavigationModule::query()->whereIn('module_key', [
            'request_rembes',
            'approval_rembes_finance',
            'laporan_keuangan',
        ])->delete();
    }
};
