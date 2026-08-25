<?php

use App\Models\AppNavigationModule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $modules = [
            [
                'module_key' => 'pelanggan',
                'label' => 'Pelanggan',
                'description' => 'Daftar seluruh pelanggan aktif hasil registrasi dan aktivasi layanan.',
                'route_target' => '/app/pelanggan',
                'navigation_head_key' => 'operasional',
                'sort_order' => 14,
                'quick_action' => null,
                'view_formats' => ['table', 'grid'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key' => 'penagihan',
                'label' => 'Penagihan',
                'description' => 'Monitoring masa aktif 30 hari, status tagihan, dan aksi perpanjang paket pelanggan.',
                'route_target' => '/app/penagihan',
                'navigation_head_key' => 'operasional',
                'sort_order' => 15,
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
    }

    public function down(): void
    {
        AppNavigationModule::query()
            ->whereIn('module_key', ['pelanggan', 'penagihan'])
            ->delete();
    }
};
