<?php

namespace Database\Seeders;

use App\Models\AppNavigationModule;
use App\Models\NavigationHead;
use App\Models\Role;
use App\Models\RoleModuleMapping;
use Illuminate\Database\Seeder;

class ComprehensiveModulesSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure Navigation Heads exist
        $heads = [
            ['key' => 'core_network', 'label' => 'Jaringan & Infrastruktur', 'sort_order' => 1],
            ['key' => 'customer_services', 'label' => 'Layanan Pelanggan & DataSheet', 'sort_order' => 2],
            ['key' => 'finance_billing', 'label' => 'Billing & Keuangan', 'sort_order' => 3],
            ['key' => 'warehouse_inventory', 'label' => 'Gudang & Inventaris', 'sort_order' => 4],
            ['key' => 'ticketing_field', 'label' => 'Tiket Gangguan & PSB', 'sort_order' => 5],
            ['key' => 'system_logs', 'label' => 'Sistem & Konfigurasi', 'sort_order' => 6],
        ];

        foreach ($heads as $h) {
            NavigationHead::updateOrCreate(['key' => $h['key']], $h);
        }

        // 2. Register Navigation Modules
        $modules = [
            [
                'module_key'           => 'router_management',
                'label'                => 'Router & Bandwidth',
                'description'          => 'Manajemen router MikroTik (Core, CCR, CRS, Switch), realtime traffic & SFP telemetry.',
                'route_target'         => '/routers',
                'navigation_head_key'  => 'core_network',
                'sort_order'           => 1,
                'view_formats'         => ['table', 'cards', 'telemetry'],
                'is_active'            => true,
                'show_in_navbar'       => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key'           => 'olt_monitoring',
                'label'                => 'Monitoring OLT',
                'description'          => 'Monitoring OLT (HSGQ, Global, ZTE, Huawei), port PON, redaman & optical power ONU.',
                'route_target'         => '/olts',
                'navigation_head_key'  => 'core_network',
                'sort_order'           => 2,
                'view_formats'         => ['table', 'cards', 'map'],
                'is_active'            => true,
                'show_in_navbar'       => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key'           => 'odp_management',
                'label'                => 'Distribusi ODP & GIS',
                'description'          => 'Topologi distribusi ODP, jalur fiber optik, status gangguan & GIS mapping.',
                'route_target'         => '/odps',
                'navigation_head_key'  => 'core_network',
                'sort_order'           => 3,
                'view_formats'         => ['table', 'map'],
                'is_active'            => true,
                'show_in_navbar'       => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key'           => 'datasheet_360',
                'label'                => 'DataSheet Pelanggan 360°',
                'description'          => 'Data sheet pelanggan lengkap, galeri foto dokumentasi, sinkronisasi Google Sheets.',
                'route_target'         => '/datasheet',
                'navigation_head_key'  => 'customer_services',
                'sort_order'           => 1,
                'view_formats'         => ['table', 'gallery', '360_profile'],
                'is_active'            => true,
                'show_in_navbar'       => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key'           => 'sync_check',
                'label'                => 'Audit & Sync Check',
                'description'          => 'Audit silang 3-arah antara MikroTik, Database Lokal, dan Google Sheets.',
                'route_target'         => '/sync-check',
                'navigation_head_key'  => 'customer_services',
                'sort_order'           => 2,
                'view_formats'         => ['table', 'audit_matrix'],
                'is_active'            => true,
                'show_in_navbar'       => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key'           => 'master_wilayah',
                'label'                => 'Master Wilayah & ID',
                'description'          => 'Hierarki wilayah administratif dan generator Customer ID serta akun PPPoE.',
                'route_target'         => '/master-wilayah',
                'navigation_head_key'  => 'customer_services',
                'sort_order'           => 3,
                'view_formats'         => ['table', 'generator'],
                'is_active'            => true,
                'show_in_navbar'       => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key'           => 'paket_internet',
                'label'                => 'Paket Layanan Internet',
                'description'          => 'Konfigurasi profil paket internet, tarif bulanan, dan binding MikroTik profile.',
                'route_target'         => '/pakets',
                'navigation_head_key'  => 'finance_billing',
                'sort_order'           => 1,
                'view_formats'         => ['cards', 'table'],
                'is_active'            => true,
                'show_in_navbar'       => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key'           => 'billing_invoices',
                'label'                => 'Billing & Invoicing',
                'description'          => 'Penerbitan invoice bulanan otomatis, payment settlement, auto-isolir, dan notifikasi WA.',
                'route_target'         => '/invoices',
                'navigation_head_key'  => 'finance_billing',
                'sort_order'           => 2,
                'view_formats'         => ['table', 'printable_receipt', 'analytics'],
                'is_active'            => true,
                'show_in_navbar'       => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key'           => 'package_requests',
                'label'                => 'Pengajuan Paket',
                'description'          => 'Alur pengajuan upgrade/downgrade paket pelanggan dengan persetujuan Finance.',
                'route_target'         => '/package-requests',
                'navigation_head_key'  => 'finance_billing',
                'sort_order'           => 3,
                'view_formats'         => ['table'],
                'is_active'            => true,
                'show_in_navbar'       => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key'           => 'warehouse_management',
                'label'                => 'Gudang & Stok Material',
                'description'          => 'Manajemen stok material, pengajuan barang divisi, retur cabut alat, dan aset inventaris.',
                'route_target'         => '/warehouse',
                'navigation_head_key'  => 'warehouse_inventory',
                'sort_order'           => 1,
                'view_formats'         => ['table', 'cards', 'mutations'],
                'is_active'            => true,
                'show_in_navbar'       => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key'           => 'comprehensive_tickets',
                'label'                => 'Tiket Gangguan & PSB',
                'description'          => 'Sistem ticketing terpadu, validasi OCR barcode ONT, dispatch teknisi, dan aktivasi PSB.',
                'route_target'         => '/tickets',
                'navigation_head_key'  => 'ticketing_field',
                'sort_order'           => 1,
                'view_formats'         => ['queue_table', 'live_board', 'detail_workflow'],
                'is_active'            => true,
                'show_in_navbar'       => true,
                'admin_only_dashboard' => false,
            ],
            [
                'module_key'           => 'settings_isp',
                'label'                => 'Pengaturan ISP & Gateway',
                'description'          => 'Konfigurasi parameter ISP, WhatsApp Gateway, Telegram Bot, dan folder Google Drive.',
                'route_target'         => '/settings',
                'navigation_head_key'  => 'system_logs',
                'sort_order'           => 1,
                'view_formats'         => ['form'],
                'is_active'            => true,
                'show_in_navbar'       => true,
                'admin_only_dashboard' => true,
            ],
            [
                'module_key'           => 'activity_logs',
                'label'                => 'Log Aktivitas & Audit',
                'description'          => 'Catatan aktivitas pengguna, log PPPoE realtime, dan audit trail sistem.',
                'route_target'         => '/activity-logs',
                'navigation_head_key'  => 'system_logs',
                'sort_order'           => 2,
                'view_formats'         => ['table', 'stream'],
                'is_active'            => true,
                'show_in_navbar'       => true,
                'admin_only_dashboard' => true,
            ],
        ];

        foreach ($modules as $m) {
            AppNavigationModule::updateOrCreate(['module_key' => $m['module_key']], $m);
        }

        // 3. Grant access mappings for existing roles
        $roles = Role::all();
        foreach ($roles as $role) {
            foreach ($modules as $mod) {
                $isVisible = true;

                if ($role->key === 'teknisi') {
                    $isVisible = in_array($mod['module_key'], ['comprehensive_tickets', 'warehouse_management', 'datasheet_360', 'odp_management', 'olt_monitoring'], true);
                } elseif ($role->key === 'noc') {
                    $isVisible = !in_array($mod['module_key'], ['settings_isp'], true);
                } elseif ($role->key === 'finance') {
                    $isVisible = in_array($mod['module_key'], ['billing_invoices', 'package_requests', 'paket_internet', 'warehouse_management', 'datasheet_360'], true);
                } elseif ($role->key === 'marketing' || $role->key === 'sales') {
                    $isVisible = in_array($mod['module_key'], ['datasheet_360', 'comprehensive_tickets', 'paket_internet', 'master_wilayah', 'package_requests'], true);
                }

                RoleModuleMapping::updateOrCreate(
                    [
                        'role'       => $role->key,
                        'module_key' => $mod['module_key'],
                    ],
                    [
                        'is_visible'     => $isVisible,
                        'order_override' => $mod['sort_order'],
                    ]
                );
            }
        }
    }
}
