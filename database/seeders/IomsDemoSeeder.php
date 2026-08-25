<?php

namespace Database\Seeders;

use App\Models\AdminMasterDataGroup;
use App\Models\AppNavigationModule;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InterDivisionTask;
use App\Models\InventoryItem;
use App\Models\InventorySerial;
use App\Models\NetworkOdp;
use App\Models\NetworkOdpPort;
use App\Models\NavigationHead;
use App\Models\ProcurementRequest;
use App\Models\Role;
use App\Models\RoleModuleMapping;
use App\Models\TroubleTicket;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class IomsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['id' => 'USR-01', 'name' => 'Budi Santoso', 'email' => 'superadmin@isp-ops.net', 'role' => 'superadmin', 'role_title' => 'Super Administrator', 'division' => 'IT & System Development', 'phone' => '081234567890'],
            ['id' => 'USR-02', 'name' => 'Ir. Hendra Gunawan', 'email' => 'hendra.direksi@isp-ops.net', 'role' => 'management', 'role_title' => 'Direktur Operasional & Bisnis', 'division' => 'Executive Management', 'phone' => '081298765432'],
            ['id' => 'USR-03', 'name' => 'Raka Pratama', 'email' => 'sales@isp-ops.net', 'role' => 'sales', 'role_title' => 'Sales Fiber Consultant', 'division' => 'Sales & Acquisition', 'phone' => '081355667788'],
            ['id' => 'USR-04', 'name' => 'Ahmad Fauzi', 'email' => 'noc.lead@isp-ops.net', 'role' => 'noc', 'role_title' => 'Senior Network Engineer', 'division' => 'Network Operation Center', 'phone' => '081388776655'],
            ['id' => 'USR-05', 'name' => 'Rina Kartika', 'email' => 'helpdesk@isp-ops.net', 'role' => 'helpdesk', 'role_title' => 'Customer Care & Helpdesk', 'division' => 'Customer Service & Helpdesk', 'phone' => '081911223344'],
            ['id' => 'USR-06', 'name' => 'Supriyadi', 'email' => 'lead.tech@isp-ops.net', 'role' => 'lead_tech', 'role_title' => 'Kepala Teknisi Lapangan', 'division' => 'Field Operations & Dispatch', 'phone' => '082133445566'],
            ['id' => 'USR-07', 'name' => 'Bambang Irawan', 'email' => 'teknisi.bambang@isp-ops.net', 'role' => 'field_tech', 'role_title' => 'Teknisi Instalasi & FO', 'division' => 'Field Operations', 'phone' => '085711223399'],
            ['id' => 'USR-08', 'name' => 'Dinda Permata', 'email' => 'finance.billing@isp-ops.net', 'role' => 'finance', 'role_title' => 'Finance & Billing Specialist', 'division' => 'Finance, Billing & Accounting', 'phone' => '087811992288'],
            ['id' => 'USR-09', 'name' => 'Joko Widodo', 'email' => 'gudang.inventory@isp-ops.net', 'role' => 'inventory', 'role_title' => 'Logistik & Asset Inventory', 'division' => 'Warehouse & Asset Logistics', 'phone' => '081244556677'],
        ];

        foreach ($users as $user) {
            User::query()->updateOrCreate(
                ['id' => $user['id']],
                [...$user, 'avatar' => null, 'is_online' => true, 'is_active' => true, 'last_login_at' => now(), 'email_verified_at' => now(), 'password' => Hash::make('password')]
            );
        }

        $roles = [
            ['key' => 'superadmin', 'label' => 'Super Administrator', 'division' => 'IT & System Development', 'dashboard_module_key' => 'dashboard', 'description' => 'Akses penuh ke dashboard admin sistem dan seluruh modul operasional.', 'sort_order' => 1],
            ['key' => 'management', 'label' => 'Direktur Operasional & Bisnis', 'division' => 'Executive Management', 'dashboard_module_key' => 'dashboard', 'description' => 'Dashboard analitik dan approval manajemen.', 'sort_order' => 2],
            ['key' => 'sales', 'label' => 'Sales Fiber Consultant', 'division' => 'Sales & Acquisition', 'dashboard_module_key' => 'service_registrations', 'description' => 'Registrasi pelanggan baru dan handoff ke finance.', 'sort_order' => 3],
            ['key' => 'noc', 'label' => 'Senior Network Engineer', 'division' => 'Network Operation Center', 'dashboard_module_key' => 'noc', 'description' => 'Validasi teknis, PPPoE, dan final verify instalasi.', 'sort_order' => 4],
            ['key' => 'helpdesk', 'label' => 'Customer Care & Helpdesk', 'division' => 'Customer Service & Helpdesk', 'dashboard_module_key' => 'helpdesk', 'description' => 'Intake aduan dan koordinasi tiket gangguan.', 'sort_order' => 5],
            ['key' => 'lead_tech', 'label' => 'Kepala Teknisi Lapangan', 'division' => 'Field Operations & Dispatch', 'dashboard_module_key' => 'service_registrations', 'description' => 'Dispatch teknisi dan review SOP lapangan.', 'sort_order' => 6],
            ['key' => 'field_tech', 'label' => 'Teknisi Instalasi & FO', 'division' => 'Field Operations', 'dashboard_module_key' => 'field_tech', 'description' => 'Eksekusi work order on-site dan laporan teknisi.', 'sort_order' => 7],
            ['key' => 'finance', 'label' => 'Finance & Billing Specialist', 'division' => 'Finance, Billing & Accounting', 'dashboard_module_key' => 'finance', 'description' => 'Approval billing dan procurement finance.', 'sort_order' => 8],
            ['key' => 'inventory', 'label' => 'Logistik & Asset Inventory', 'division' => 'Warehouse & Asset Logistics', 'dashboard_module_key' => 'inventory', 'description' => 'Gudang, stok, dan permintaan barang.', 'sort_order' => 9],
        ];

        foreach ($roles as $role) {
            Role::query()->updateOrCreate(
                ['key' => $role['key']],
                [...$role, 'is_active' => true]
            );
        }

        $masterGroups = [
            [
                'key' => 'regions',
                'label' => 'Regions & Clusters',
                'items' => [
                    ['name' => 'Sidoarjo Kota', 'clusterCode' => 'SDA'],
                    ['name' => 'Waru', 'clusterCode' => 'WAR'],
                    ['name' => 'Gedangan', 'clusterCode' => 'GED'],
                    ['name' => 'Krian', 'clusterCode' => 'KRN'],
                    ['name' => 'Banjar', 'clusterCode' => 'BNJ'],
                ],
                'editable_fields' => ['name', 'clusterCode'],
            ],
            [
                'key' => 'service_packages',
                'label' => 'Service Packages',
                'items' => [
                    ['name' => 'Home 20 Mbps', 'monthlyFee' => 185000],
                    ['name' => 'Home 50 Mbps', 'monthlyFee' => 285000],
                    ['name' => 'Gamer 100 Mbps', 'monthlyFee' => 425000],
                    ['name' => 'Business 200 Mbps', 'monthlyFee' => 825000],
                ],
                'editable_fields' => ['name', 'monthlyFee'],
            ],
            [
                'key' => 'inventory_references',
                'label' => 'Inventory References',
                'items' => [
                    ['category' => 'ONT', 'unit' => 'Unit', 'brand' => 'ZTE'],
                    ['category' => 'Patch Cord', 'unit' => 'Pcs', 'brand' => 'V-Sol'],
                    ['category' => 'Drop Cable', 'unit' => 'Meter', 'brand' => 'Supreme'],
                ],
                'editable_fields' => ['category', 'unit', 'brand'],
            ],
            [
                'key' => 'workflow_references',
                'label' => 'Workflow References',
                'items' => [
                    ['name' => 'Ticket Open', 'targetDivision' => 'Helpdesk'],
                    ['name' => 'NOC Review', 'targetDivision' => 'NOC'],
                    ['name' => 'Lead Approval', 'targetDivision' => 'Field Operations & Dispatch'],
                    ['name' => 'Procurement Finance Approval', 'targetDivision' => 'Finance, Billing & Accounting'],
                ],
                'editable_fields' => ['name', 'targetDivision'],
            ],
        ];

        foreach ($masterGroups as $group) {
            AdminMasterDataGroup::query()->updateOrCreate(
                ['key' => $group['key']],
                $group,
            );
        }

        $navigationHeads = [
            ['key' => 'dashboards', 'label' => 'Dashboards', 'sort_order' => 1, 'is_active' => true],
            ['key' => 'operasional', 'label' => 'Operasional', 'sort_order' => 2, 'is_active' => true],
            ['key' => 'koordinasi', 'label' => 'Koordinasi', 'sort_order' => 3, 'is_active' => true],
            ['key' => 'infrastruktur', 'label' => 'Infrastruktur', 'sort_order' => 4, 'is_active' => true],
            ['key' => 'administrasi', 'label' => 'Administrasi Sistem', 'sort_order' => 5, 'is_active' => true],
            ['key' => 'keuangan', 'label' => 'Keuangan', 'sort_order' => 6, 'is_active' => true],
        ];

        foreach ($navigationHeads as $head) {
            NavigationHead::query()->updateOrCreate(['key' => $head['key']], $head);
        }

        $modules = [
            ['module_key' => 'dashboard', 'label' => 'Dashboard', 'description' => 'Ringkasan utama workspace.', 'route_target' => '/app/dashboard', 'navigation_head_key' => 'dashboards', 'sort_order' => 1, 'quick_action' => null, 'view_formats' => ['grid', 'table'], 'is_active' => true, 'show_in_navbar' => true, 'admin_only_dashboard' => false],
            ['module_key' => 'pelanggan', 'label' => 'Pelanggan', 'description' => 'Daftar seluruh pelanggan aktif hasil registrasi dan aktivasi layanan.', 'route_target' => '/app/pelanggan', 'navigation_head_key' => 'operasional', 'sort_order' => 8, 'quick_action' => null, 'view_formats' => ['table', 'grid'], 'is_active' => true, 'show_in_navbar' => true, 'admin_only_dashboard' => false],
            ['module_key' => 'penagihan', 'label' => 'Penagihan', 'description' => 'Monitoring masa aktif 30 hari, status tagihan, dan aksi perpanjang paket pelanggan.', 'route_target' => '/app/penagihan', 'navigation_head_key' => 'operasional', 'sort_order' => 9, 'quick_action' => null, 'view_formats' => ['table', 'grid'], 'is_active' => true, 'show_in_navbar' => true, 'admin_only_dashboard' => false],
            ['module_key' => 'service_registrations', 'label' => 'Registrasi Pasang Baru', 'description' => 'Pipeline sales, finance, NOC, dan dispatch untuk pelanggan baru.', 'route_target' => '/app/service-registrations', 'navigation_head_key' => 'operasional', 'sort_order' => 1, 'quick_action' => 'new_customer', 'view_formats' => ['table', 'grid'], 'is_active' => true, 'show_in_navbar' => true, 'admin_only_dashboard' => false],
            ['module_key' => 'helpdesk', 'label' => 'Helpdesk & Ticketing', 'description' => 'Aduan pelanggan, intake tiket, dan alur helpdesk.', 'route_target' => '/app/helpdesk', 'navigation_head_key' => 'operasional', 'sort_order' => 2, 'quick_action' => 'new_ticket', 'view_formats' => ['table', 'grid'], 'is_active' => true, 'show_in_navbar' => true, 'admin_only_dashboard' => false],
            ['module_key' => 'noc', 'label' => 'NOC Console', 'description' => 'Triage teknis, verifikasi sinyal, dan closing tiket.', 'route_target' => '/app/noc', 'navigation_head_key' => 'operasional', 'sort_order' => 3, 'quick_action' => null, 'view_formats' => ['table', 'grid'], 'is_active' => true, 'show_in_navbar' => true, 'admin_only_dashboard' => false],
            ['module_key' => 'lead_tech', 'label' => 'Lead Technician Workspace', 'description' => 'Assign work order, review SOP, dan monitoring teknisi.', 'route_target' => '/app/lead-tech', 'navigation_head_key' => 'operasional', 'sort_order' => 4, 'quick_action' => null, 'view_formats' => ['table', 'grid'], 'is_active' => true, 'show_in_navbar' => true, 'admin_only_dashboard' => false],
            ['module_key' => 'field_tech', 'label' => 'Portal Teknisi Lapangan', 'description' => 'Eksekusi WO, bukti kerja, dan laporan on-site.', 'route_target' => '/app/field-tech', 'navigation_head_key' => 'operasional', 'sort_order' => 5, 'quick_action' => null, 'view_formats' => ['table'], 'is_active' => true, 'show_in_navbar' => true, 'admin_only_dashboard' => false],
            ['module_key' => 'finance', 'label' => 'Finance Desk', 'description' => 'Billing pelanggan dan approval procurement finance.', 'route_target' => '/app/finance', 'navigation_head_key' => 'operasional', 'sort_order' => 6, 'quick_action' => null, 'view_formats' => ['table', 'grid'], 'is_active' => true, 'show_in_navbar' => true, 'admin_only_dashboard' => false],
            ['module_key' => 'request_rembes', 'label' => 'Request Rembes', 'description' => 'Pengajuan rembes pegawai.', 'route_target' => '/app/request-rembes', 'navigation_head_key' => 'keuangan', 'sort_order' => 1, 'quick_action' => null, 'view_formats' => ['table', 'grid'], 'is_active' => true, 'show_in_navbar' => true, 'admin_only_dashboard' => false],
            ['module_key' => 'approval_rembes_finance', 'label' => 'Approval Rembes Finance', 'description' => 'Review, approval, dan pencairan rembes.', 'route_target' => '/app/approval-rembes-finance', 'navigation_head_key' => 'keuangan', 'sort_order' => 2, 'quick_action' => null, 'view_formats' => ['table', 'grid'], 'is_active' => true, 'show_in_navbar' => true, 'admin_only_dashboard' => false],
            ['module_key' => 'laporan_keuangan', 'label' => 'Laporan Keuangan', 'description' => 'Ledger billing, rembes, dan mutasi.', 'route_target' => '/app/laporan-keuangan', 'navigation_head_key' => 'keuangan', 'sort_order' => 3, 'quick_action' => null, 'view_formats' => ['table', 'grid'], 'is_active' => true, 'show_in_navbar' => true, 'admin_only_dashboard' => false],
            ['module_key' => 'inventory', 'label' => 'Warehouse Console', 'description' => 'Stok barang, serial aset, dan permintaan pengadaan.', 'route_target' => '/app/inventory', 'navigation_head_key' => 'operasional', 'sort_order' => 7, 'quick_action' => 'new_procurement', 'view_formats' => ['table', 'grid'], 'is_active' => true, 'show_in_navbar' => true, 'admin_only_dashboard' => false],
            ['module_key' => 'kanban', 'label' => 'Kanban Koordinasi', 'description' => 'Koordinasi tugas antar divisi internal.', 'route_target' => '/app/kanban', 'navigation_head_key' => 'koordinasi', 'sort_order' => 1, 'quick_action' => 'new_task', 'view_formats' => ['kanban', 'table'], 'is_active' => true, 'show_in_navbar' => true, 'admin_only_dashboard' => false],
            ['module_key' => 'network_map', 'label' => 'Peta Jaringan', 'description' => 'ODP, port binding, dan visualisasi mapping pelanggan.', 'route_target' => '/app/network-map', 'navigation_head_key' => 'infrastruktur', 'sort_order' => 1, 'quick_action' => null, 'view_formats' => ['map', 'grid'], 'is_active' => true, 'show_in_navbar' => true, 'admin_only_dashboard' => false],
            ['module_key' => 'admin_users', 'label' => 'Manajemen Akun', 'description' => 'CRUD akun login, status aktif, dan reset password.', 'route_target' => '/app/admin/users', 'navigation_head_key' => 'administrasi', 'sort_order' => 1, 'quick_action' => null, 'view_formats' => ['table'], 'is_active' => true, 'show_in_navbar' => false, 'admin_only_dashboard' => true],
            ['module_key' => 'admin_roles', 'label' => 'Master Data Role', 'description' => 'Metadata role system dan division aplikasi.', 'route_target' => '/app/admin/roles', 'navigation_head_key' => 'administrasi', 'sort_order' => 2, 'quick_action' => null, 'view_formats' => ['table'], 'is_active' => true, 'show_in_navbar' => false, 'admin_only_dashboard' => true],
            ['module_key' => 'admin_master', 'label' => 'Master Data', 'description' => 'Referensi paket, wilayah, inventory, dan workflow.', 'route_target' => '/app/admin/master', 'navigation_head_key' => 'administrasi', 'sort_order' => 3, 'quick_action' => null, 'view_formats' => ['table'], 'is_active' => true, 'show_in_navbar' => false, 'admin_only_dashboard' => true],
            ['module_key' => 'admin_modules', 'label' => 'Master Data Modul', 'description' => 'Daftar modul aplikasi, kepala navigasi, dan link akses internal.', 'route_target' => '/app/admin/modules', 'navigation_head_key' => 'administrasi', 'sort_order' => 4, 'quick_action' => null, 'view_formats' => ['table'], 'is_active' => true, 'show_in_navbar' => false, 'admin_only_dashboard' => true],
            ['module_key' => 'admin_module_roles', 'label' => 'Modul To Role', 'description' => 'Mapping modul terhadap role untuk membentuk navigasi aplikasi.', 'route_target' => '/app/admin/module-roles', 'navigation_head_key' => 'administrasi', 'sort_order' => 5, 'quick_action' => null, 'view_formats' => ['table'], 'is_active' => true, 'show_in_navbar' => false, 'admin_only_dashboard' => true],
            ['module_key' => 'admin_mappings', 'label' => 'Mapping Infrastruktur', 'description' => 'ODP, port binding, dan relasi entitas aplikasi.', 'route_target' => '/app/admin/mappings', 'navigation_head_key' => 'administrasi', 'sort_order' => 6, 'quick_action' => null, 'view_formats' => ['table'], 'is_active' => true, 'show_in_navbar' => false, 'admin_only_dashboard' => true],
            ['module_key' => 'admin_audit', 'label' => 'Audit & Session', 'description' => 'Jejak aktivitas dan sesi user online.', 'route_target' => '/app/admin/audit', 'navigation_head_key' => 'administrasi', 'sort_order' => 7, 'quick_action' => null, 'view_formats' => ['table'], 'is_active' => true, 'show_in_navbar' => false, 'admin_only_dashboard' => true],
        ];

        foreach ($modules as $module) {
            AppNavigationModule::query()->updateOrCreate(
                ['module_key' => $module['module_key']],
                $module,
            );
        }

        $roleMappings = [
            'management' => ['dashboard'],
            'sales' => ['service_registrations', 'kanban', 'request_rembes'],
            'helpdesk' => ['helpdesk', 'kanban', 'request_rembes'],
            'noc' => ['service_registrations', 'noc', 'network_map', 'kanban', 'request_rembes'],
            'lead_tech' => ['service_registrations', 'lead_tech', 'kanban', 'request_rembes'],
            'field_tech' => ['field_tech', 'request_rembes'],
            'finance' => ['service_registrations', 'finance', 'approval_rembes_finance', 'laporan_keuangan', 'request_rembes', 'kanban'],
            'inventory' => ['inventory', 'kanban', 'request_rembes'],
            'management' => ['dashboard', 'approval_rembes_finance', 'laporan_keuangan', 'request_rembes'],
        ];

        foreach ($roleMappings as $role => $moduleKeys) {
            foreach ($moduleKeys as $index => $moduleKey) {
                RoleModuleMapping::query()->updateOrCreate(
                    ['role' => $role, 'module_key' => $moduleKey],
                    ['is_visible' => true, 'order_override' => $index + 1]
                );
            }
        }

        $odp = NetworkOdp::query()->updateOrCreate(
            ['id' => 'ODP-SDA-01/01'],
            [
                'odc_id' => 'ODC-SDA-01',
                'region' => 'Sidoarjo Kota',
                'total_ports' => 8,
                'used_ports' => 1,
                'splitter_ratio' => '1:8',
                'olt_host' => 'OLT-ZTE-C320-SDA',
                'pon_slot' => 'GPON 1/1/2',
                'fiber_core_color' => 'Biru',
                'latitude' => -7.4469311,
                'longitude' => 112.7182891,
                'address' => 'Jl. Pahlawan No. 45, Lemahputro',
            ]
        );

        for ($port = 1; $port <= 8; $port++) {
            NetworkOdpPort::query()->updateOrCreate(
                ['network_odp_id' => $odp->id, 'port_number' => $port],
                ['status' => $port === 3 ? 'active' : 'empty']
            );
        }

        $customer = Customer::query()->updateOrCreate(
            ['id' => 'CUST-1042'],
            [
                'name' => 'H. Sudirman',
                'nik' => '3515081203800004',
                'phone' => '081234567811',
                'address' => 'Jl. Pahlawan No. 45, RT 03/RW 02, Lemahputro',
                'region' => 'Sidoarjo Kota',
                'package_plan' => 'Home 50 Mbps',
                'monthly_fee' => 285000,
                'pppoe_username' => 'cust1042@isp.net',
                'pppoe_password' => 'Zk9#mP2$xL',
                'ip_address' => '10.20.14.42',
                'ont_brand' => 'ZTE',
                'ont_model' => 'ZTE F609 V3',
                'ont_serial_number' => 'ZTEGCA48B21F',
                'odc_id' => 'ODC-SDA-01',
                'odp_id' => $odp->id,
                'odp_port' => 3,
                'fiber_core_color' => 'Biru',
                'optical_power_dbm' => -20.8,
                'status' => 'active',
                'billing_status' => 'paid',
                'billing_due_date' => '2026-08-20',
                'installed_date' => '2025-11-12',
                'assigned_technician_id' => 'USR-06',
                'last_payment_date' => '2026-08-05',
            ]
        );

        NetworkOdpPort::query()->where('network_odp_id', $odp->id)->where('port_number', 3)->update([
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'pppoe_username' => $customer->pppoe_username,
            'optical_power_dbm' => $customer->optical_power_dbm,
            'status' => 'active',
        ]);

        TroubleTicket::query()->updateOrCreate(
            ['id' => 'TKT-2026-881'],
            [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'customer_address' => $customer->address,
                'region' => $customer->region,
                'odp_id' => $customer->odp_id,
                'category' => 'slow_connection',
                'title' => 'Koneksi melambat malam hari',
                'description' => 'Pelanggan mengeluhkan throughput turun signifikan saat jam sibuk.',
                'priority' => 'high',
                'status' => 'in_noc_review',
                'created_by' => 'Rina Kartika (Customer Care & Helpdesk)',
                'can_be_resolved_remotely' => true,
                'created_at' => Carbon::parse('2026-08-14 08:30:00'),
                'updated_at' => Carbon::parse('2026-08-14 08:30:00'),
            ]
        );

        WorkOrder::query()->updateOrCreate(
            ['id' => 'WO-2026-412'],
            [
                'type' => 'installation',
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'address' => $customer->address,
                'region' => $customer->region,
                'odp_id' => $customer->odp_id,
                'assigned_lead' => 'Supriyadi',
                'assigned_tech_id' => 'USR-06',
                'assigned_tech_name' => 'Bambang Irawan',
                'status' => 'assigned',
                'scheduled_date' => '2026-08-15 09:00',
                'package_plan' => 'Home 50 Mbps',
                'required_materials' => [
                    ['itemName' => 'Modem ONT ZTE', 'quantity' => 1, 'unit' => 'Unit'],
                    ['itemName' => 'Patch Cord SC-UPC 3M', 'quantity' => 1, 'unit' => 'Pcs'],
                ],
            ]
        );

        $inventory = InventoryItem::query()->updateOrCreate(
            ['id' => 'INV-MODEM-01'],
            [
                'code' => 'MODEM-ZTE-F609',
                'name' => 'Modem ONT ZTE F609 V3',
                'category' => 'ONT',
                'brand' => 'ZTE',
                'model' => 'F609 V3',
                'stock_available' => 12,
                'stock_in_use' => 24,
                'stock_reserved' => 2,
                'min_threshold' => 5,
                'unit' => 'Unit',
                'unit_price' => 275000,
                'location_rack' => 'Rak A-02',
            ]
        );

        InventorySerial::query()->updateOrCreate(
            ['inventory_item_id' => $inventory->id, 'sn' => 'ZTEGCA48B21F'],
            ['status' => 'assigned_to_cust', 'current_cust_id' => $customer->id]
        );

        ProcurementRequest::query()->updateOrCreate(
            ['id' => 'REQ-2026-034'],
            [
                'item_code' => $inventory->code,
                'item_name' => $inventory->name,
                'quantity' => 10,
                'unit' => 'Unit',
                'unit_price' => 275000,
                'total_amount' => 2750000,
                'reason' => 'Restock modem ONT untuk WO instalasi baru.',
                'requested_by' => 'Joko Widodo',
                'requested_at' => Carbon::parse('2026-08-14 09:00:00'),
                'status' => 'pending_finance',
            ]
        );

        InterDivisionTask::query()->updateOrCreate(
            ['id' => 'TASK-091'],
            [
                'title' => 'Validasi pelanggan area Waru',
                'description' => 'Sinkronkan data pelanggan hasil instalasi manual ke sistem.',
                'from_division' => 'Helpdesk',
                'to_division' => 'Admin Data',
                'priority' => 'high',
                'status' => 'todo',
                'created_at' => Carbon::parse('2026-08-14 08:00:00'),
                'updated_at' => Carbon::parse('2026-08-14 08:00:00'),
                'due_date' => Carbon::parse('2026-08-15 16:00:00'),
                'created_by' => 'Rina Kartika',
                'assigned_to' => 'Admin Data',
            ]
        );

        AuditLog::query()->updateOrCreate(
            ['id' => 'LOG-SEED01'],
            [
                'timestamp' => Carbon::parse('2026-08-14 09:15:00'),
                'actor_name' => 'System Seeder',
                'actor_role' => 'superadmin',
                'action' => 'Seeded Demo Data',
                'target' => 'Initial dataset',
                'details' => 'Demo dataset untuk frontend dan pengujian lokal.',
                'type' => 'info',
            ]
        );
    }
}
