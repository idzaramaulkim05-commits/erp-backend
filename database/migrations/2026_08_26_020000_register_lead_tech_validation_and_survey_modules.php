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
            ['label' => 'Operasional', 'sort_order' => 2, 'is_active' => true]
        );

        $modules = [
            [
                'module_key' => 'validasi_registrasi',
                'label' => 'Validasi Registrasi',
                'description' => 'Antrean verifikasi kelengkapan data registrasi pelanggan sebelum survey.',
                'route_target' => '/app/validasi-registrasi',
                'navigation_head_key' => 'operasional',
                'sort_order' => 15,
                'quick_action' => null,
                'view_formats' => ['table', 'grid'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key' => 'survey_instalasi',
                'label' => 'Survey Instalasi',
                'description' => 'Kelayakan instalasi, ODP, jalur, dan kebutuhan teknis survey.',
                'route_target' => '/app/survey-instalasi',
                'navigation_head_key' => 'operasional',
                'sort_order' => 16,
                'quick_action' => null,
                'view_formats' => ['table', 'grid'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key' => 'registrasi_pelanggan_baru',
                'label' => 'Registrasi Pelanggan Baru',
                'description' => 'Intake internal pelanggan baru, paket, lokasi, dan data awal instalasi.',
                'route_target' => '/app/registrasi-pelanggan-baru',
                'navigation_head_key' => 'operasional',
                'sort_order' => 14,
                'quick_action' => null,
                'view_formats' => ['table', 'grid'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key' => 'panel_kepala_teknisi',
                'label' => 'Panel Kepala Teknisi',
                'description' => 'Panel ringkasan distribusi WO, tim teknisi, dan monitoring instalasi.',
                'route_target' => '/app/panel-kepala-teknisi',
                'navigation_head_key' => 'operasional',
                'sort_order' => 17,
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
                $module
            );
        }

        // Map validasi_registrasi, survey_instalasi, panel_kepala_teknisi to lead_tech
        RoleModuleMapping::query()->updateOrCreate(
            ['role' => 'lead_tech', 'module_key' => 'validasi_registrasi'],
            ['is_visible' => true, 'order_override' => 2]
        );
        RoleModuleMapping::query()->updateOrCreate(
            ['role' => 'lead_tech', 'module_key' => 'survey_instalasi'],
            ['is_visible' => true, 'order_override' => 3]
        );
        RoleModuleMapping::query()->updateOrCreate(
            ['role' => 'lead_tech', 'module_key' => 'panel_kepala_teknisi'],
            ['is_visible' => true, 'order_override' => 1]
        );

        // Map helpdesk
        RoleModuleMapping::query()->updateOrCreate(
            ['role' => 'helpdesk', 'module_key' => 'registrasi_pelanggan_baru'],
            ['is_visible' => true, 'order_override' => 2]
        );
        RoleModuleMapping::query()->updateOrCreate(
            ['role' => 'helpdesk', 'module_key' => 'validasi_registrasi'],
            ['is_visible' => true, 'order_override' => 3]
        );

        // Map sales
        RoleModuleMapping::query()->updateOrCreate(
            ['role' => 'sales', 'module_key' => 'registrasi_pelanggan_baru'],
            ['is_visible' => true, 'order_override' => 1]
        );
    }

    public function down(): void
    {
        RoleModuleMapping::query()->whereIn('module_key', [
            'validasi_registrasi',
            'survey_instalasi',
            'registrasi_pelanggan_baru',
            'panel_kepala_teknisi',
        ])->delete();

        AppNavigationModule::query()->whereIn('module_key', [
            'validasi_registrasi',
            'survey_instalasi',
            'registrasi_pelanggan_baru',
            'panel_kepala_teknisi',
        ])->delete();
    }
};
