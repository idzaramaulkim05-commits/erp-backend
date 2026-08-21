<?php

namespace Database\Seeders;

use App\Models\AdminMasterDataGroup;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InterDivisionTask;
use App\Models\InventoryItem;
use App\Models\InventorySerial;
use App\Models\NetworkOdp;
use App\Models\NetworkOdpPort;
use App\Models\ProcurementRequest;
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
            ['id' => 'USR-03', 'name' => 'Ahmad Fauzi', 'email' => 'noc.lead@isp-ops.net', 'role' => 'noc', 'role_title' => 'Senior Network Engineer', 'division' => 'Network Operation Center', 'phone' => '081388776655'],
            ['id' => 'USR-04', 'name' => 'Rina Kartika', 'email' => 'helpdesk@isp-ops.net', 'role' => 'helpdesk', 'role_title' => 'Customer Care & Helpdesk', 'division' => 'Customer Service & Helpdesk', 'phone' => '081911223344'],
            ['id' => 'USR-05', 'name' => 'Supriyadi', 'email' => 'lead.tech@isp-ops.net', 'role' => 'lead_tech', 'role_title' => 'Kepala Teknisi Lapangan', 'division' => 'Field Operations & Dispatch', 'phone' => '082133445566'],
            ['id' => 'USR-06', 'name' => 'Bambang Irawan', 'email' => 'teknisi.bambang@isp-ops.net', 'role' => 'field_tech', 'role_title' => 'Teknisi Instalasi & FO', 'division' => 'Field Operations', 'phone' => '085711223399'],
            ['id' => 'USR-07', 'name' => 'Dinda Permata', 'email' => 'finance.billing@isp-ops.net', 'role' => 'finance', 'role_title' => 'Finance & Billing Specialist', 'division' => 'Finance, Billing & Accounting', 'phone' => '087811992288'],
            ['id' => 'USR-08', 'name' => 'Joko Widodo', 'email' => 'gudang.inventory@isp-ops.net', 'role' => 'inventory', 'role_title' => 'Logistik & Asset Inventory', 'division' => 'Warehouse & Asset Logistics', 'phone' => '081244556677'],
        ];

        foreach ($users as $user) {
            User::query()->updateOrCreate(
                ['id' => $user['id']],
                [...$user, 'avatar' => null, 'is_online' => true, 'is_active' => true, 'last_login_at' => now(), 'email_verified_at' => now(), 'password' => Hash::make('password')]
            );
        }

        $masterGroups = [
            [
                'key' => 'role_division_map',
                'label' => 'Role & Division Mapping',
                'items' => [
                    ['role' => 'superadmin', 'roleTitle' => 'Super Administrator', 'division' => 'IT & System Development'],
                    ['role' => 'management', 'roleTitle' => 'Direktur Operasional & Bisnis', 'division' => 'Executive Management'],
                    ['role' => 'noc', 'roleTitle' => 'Senior Network Engineer', 'division' => 'Network Operation Center'],
                    ['role' => 'helpdesk', 'roleTitle' => 'Customer Care & Helpdesk', 'division' => 'Customer Service & Helpdesk'],
                    ['role' => 'lead_tech', 'roleTitle' => 'Kepala Teknisi Lapangan', 'division' => 'Field Operations & Dispatch'],
                    ['role' => 'field_tech', 'roleTitle' => 'Teknisi Instalasi & FO', 'division' => 'Field Operations'],
                    ['role' => 'finance', 'roleTitle' => 'Finance & Billing Specialist', 'division' => 'Finance, Billing & Accounting'],
                    ['role' => 'inventory', 'roleTitle' => 'Logistik & Asset Inventory', 'division' => 'Warehouse & Asset Logistics'],
                ],
                'editable_fields' => ['roleTitle', 'division'],
            ],
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
