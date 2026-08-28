<?php

use App\Models\AppNavigationModule;
use App\Models\NavigationHead;
use App\Models\RoleModuleMapping;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Table network_pops (Master Data POP / Server Cabang)
        Schema::create('network_pops', function (Blueprint $table) {
            $table->string('id')->primary(); // e.g. POP-SDA-01
            $table->string('name');
            $table->string('code')->unique(); // e.g. SDA-01
            $table->string('region');
            $table->string('cluster_code')->nullable();
            $table->text('address');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('pic_name')->nullable();
            $table->string('pic_phone', 32)->nullable();
            $table->string('power_backup_info')->nullable();
            $table->string('rack_capacity')->nullable();
            $table->string('status', 32)->default('active'); // active, maintenance, inactive
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 2. Table pop_devices (Inventori Perangkat Terpasang di POP)
        Schema::create('pop_devices', function (Blueprint $table) {
            $table->string('id')->primary(); // e.g. DEV-POP-001
            $table->string('network_pop_id');
            $table->string('inventory_item_id')->nullable();
            $table->string('category', 64); // OLT, Switch Core, Router / BRAS, Rectifier, Baterai Bank, UPS, SFP Module, dsb.
            $table->string('brand');
            $table->string('model');
            $table->string('serial_number')->nullable();
            $table->string('mac_address')->nullable();
            $table->string('ip_management')->nullable();
            $table->string('rack_position')->nullable(); // e.g. Rack 1 - U12
            $table->string('power_source')->nullable(); // e.g. Rectifier 48V Port 3
            $table->string('status', 32)->default('active'); // active, backup, maintenance, faulty, decommissioned
            $table->datetime('installed_at')->nullable();
            $table->string('installed_by')->nullable();
            $table->datetime('last_checked_at')->nullable();
            $table->json('specifications')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('network_pop_id')->references('id')->on('network_pops')->cascadeOnDelete();
        });

        // 3. Table pop_work_orders (Instruksi Kerja, Penambahan, Penggantian & QC POP)
        Schema::create('pop_work_orders', function (Blueprint $table) {
            $table->string('id')->primary(); // e.g. WO-POP-2026-001
            $table->string('network_pop_id');
            $table->string('action_type', 32); // add_device, replace_device, modify_config, remove_device
            $table->string('title');
            $table->text('description');
            $table->string('priority', 16)->default('medium'); // low, medium, high, critical
            $table->string('status', 32)->default('pending_lead_tech'); // pending_lead_tech, assigned_to_tech, in_progress, waiting_noc_qc, completed, rejected_by_noc, cancelled
            $table->string('target_device_id')->nullable();
            $table->json('target_device_info')->nullable();
            $table->json('new_device_payload')->nullable();
            $table->json('materials_from_warehouse')->nullable();
            $table->string('assigned_lead_name')->nullable();
            $table->string('assigned_tech_id')->nullable();
            $table->string('assigned_tech_name')->nullable();
            $table->string('scheduled_date')->nullable();
            $table->json('field_report')->nullable();
            $table->json('noc_instruction')->nullable();
            $table->json('noc_qc_result')->nullable();
            $table->string('warehouse_return_status', 32)->nullable(); // none, pending_qc, retur_selesai
            $table->string('created_by');
            $table->timestamps();

            $table->foreign('network_pop_id')->references('id')->on('network_pops')->cascadeOnDelete();
            $table->foreign('assigned_tech_id')->references('id')->on('users')->nullOnDelete();
        });

        // 4. Register Module in App Navigation
        NavigationHead::query()->updateOrCreate(
            ['key' => 'infrastruktur'],
            ['label' => 'Infrastruktur', 'sort_order' => 4, 'is_active' => true]
        );

        AppNavigationModule::query()->updateOrCreate(
            ['module_key' => 'inventory_pop'],
            [
                'label' => 'Inventory POP',
                'description' => 'Manajemen server cabang, inventori perangkat terpasang (OLT, Switch, Power), dan alur instruksi kerja POP.',
                'route_target' => '/app/inventory-pop',
                'navigation_head_key' => 'infrastruktur',
                'sort_order' => 20,
                'quick_action' => null,
                'view_formats' => ['grid', 'table'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ]
        );

        foreach (['noc', 'inventory', 'lead_tech', 'field_tech', 'superadmin', 'management'] as $role) {
            RoleModuleMapping::query()->updateOrCreate(
                ['role' => $role, 'module_key' => 'inventory_pop'],
                ['is_visible' => true]
            );
        }

        // 5. Seed Initial Sample Data for POPs and Devices
        $pops = [
            [
                'id' => 'POP-SDA-01',
                'name' => 'POP Sidoarjo Kota - Alun-Alun Hub',
                'code' => 'SDA-01',
                'region' => 'Sidoarjo Kota',
                'cluster_code' => 'SDA',
                'address' => 'Jl. Pahlawan No. 45, Lemahputro, Sidoarjo',
                'latitude' => -7.4469311,
                'longitude' => 112.7182891,
                'pic_name' => 'Ahmad Fauzi (NOC)',
                'pic_phone' => '081388776655',
                'power_backup_info' => 'Rectifier Delta 48V 50A + Baterai Shoto 100Ah (Backup 8 Jam)',
                'rack_capacity' => '42U (Terpakai 24U)',
                'status' => 'active',
                'notes' => 'Hub utama distribusi GPON dan agregasi metro cluster Sidoarjo Pusat.',
            ],
            [
                'id' => 'POP-WAR-01',
                'name' => 'POP Waru - Shelter Gateway',
                'code' => 'WAR-01',
                'region' => 'Waru',
                'cluster_code' => 'WAR',
                'address' => 'Jl. Raya Waru No. 12, Tropodo, Waru',
                'latitude' => -7.3524100,
                'longitude' => 112.7481200,
                'pic_name' => 'Ahmad Fauzi (NOC)',
                'pic_phone' => '081388776655',
                'power_backup_info' => 'UPS Online 3kVA + Dual Rectifier 48V',
                'rack_capacity' => '24U (Terpakai 16U)',
                'status' => 'active',
                'notes' => 'Server POP distribusi industri dan perumahan area Waru Utara.',
            ],
            [
                'id' => 'POP-KRN-01',
                'name' => 'POP Krian - Node Barat',
                'code' => 'KRN-01',
                'region' => 'Krian',
                'cluster_code' => 'KRN',
                'address' => 'Jl. Gubernur Sunandar No. 88, Krian',
                'latitude' => -7.4082200,
                'longitude' => 112.5938100,
                'pic_name' => 'Supriyadi (Lead Tech)',
                'pic_phone' => '082133445566',
                'power_backup_info' => 'Rectifier 48V + Baterai Lithium 50Ah',
                'rack_capacity' => '24U (Terpakai 12U)',
                'status' => 'active',
                'notes' => 'POP transmisi wireless dan OLT cluster Sidoarjo Barat.',
            ],
            [
                'id' => 'POP-GED-01',
                'name' => 'POP Gedangan - Distribution Shelter',
                'code' => 'GED-01',
                'region' => 'Gedangan',
                'cluster_code' => 'GED',
                'address' => 'Jl. Achmad Yani No. 102, Gedangan',
                'latitude' => -7.3855000,
                'longitude' => 112.7302000,
                'pic_name' => 'Ahmad Fauzi (NOC)',
                'pic_phone' => '081388776655',
                'power_backup_info' => 'Rectifier 48V 30A + UPS Pro 2kVA',
                'rack_capacity' => '15U (Terpakai 9U)',
                'status' => 'active',
                'notes' => 'Mini POP sub-distribusi jalur arteri Gedangan.',
            ],
        ];

        foreach ($pops as $pop) {
            DB::table('network_pops')->updateOrInsert(
                ['id' => $pop['id']],
                array_merge($pop, ['created_at' => Carbon::now(), 'updated_at' => Carbon::now()])
            );
        }

        $devices = [
            // Devices for POP-SDA-01
            [
                'id' => 'DEV-POP-SDA-01',
                'network_pop_id' => 'POP-SDA-01',
                'category' => 'OLT',
                'brand' => 'ZTE',
                'model' => 'ZTE ZXA10 C320 GPON',
                'serial_number' => 'ZTE-C320-SDA01-8891',
                'mac_address' => '00:1A:C2:7B:44:01',
                'ip_management' => '10.10.1.10',
                'rack_position' => 'Rack 1 - Unit U18-U20',
                'power_source' => 'Rectifier 48V Bank 1 Port 1',
                'status' => 'active',
                'installed_at' => Carbon::now()->subMonths(6),
                'installed_by' => 'Bambang Irawan (Teknisi)',
                'last_checked_at' => Carbon::now()->subDays(2),
                'specifications' => json_encode([
                    'totalPonPorts' => 16,
                    'usedPonPorts' => 12,
                    'uplinkType' => '10G SFP+ Optical',
                    'firmwareVersion' => 'V2.1.0',
                    'powerConsumptionWatts' => 140,
                ]),
                'notes' => 'OLT Core utama yang menyuplai ODC-SDA-01 sampai ODC-SDA-06.',
            ],
            [
                'id' => 'DEV-POP-SDA-02',
                'network_pop_id' => 'POP-SDA-01',
                'category' => 'Switch Core',
                'brand' => 'MikroTik',
                'model' => 'CCR1036-8G-2S+ Router/Switch',
                'serial_number' => 'MT-CCR-1036-99212',
                'mac_address' => 'D4:CA:6D:88:12:4A',
                'ip_management' => '10.10.1.1',
                'rack_position' => 'Rack 1 - Unit U22',
                'power_source' => 'AC 220V UPS Backup',
                'status' => 'active',
                'installed_at' => Carbon::now()->subMonths(6),
                'installed_by' => 'Ahmad Fauzi (NOC)',
                'last_checked_at' => Carbon::now()->subDays(1),
                'specifications' => json_encode([
                    'cpuCores' => 36,
                    'ram' => '4GB',
                    'sfpPlusPorts' => 2,
                    'gigabitPorts' => 8,
                ]),
                'notes' => 'Gateway agregasi traffic cluster Sidoarjo Kota.',
            ],
            [
                'id' => 'DEV-POP-SDA-03',
                'network_pop_id' => 'POP-SDA-01',
                'category' => 'Rectifier',
                'brand' => 'Delta',
                'model' => 'Delta ESR-48/50D Power System',
                'serial_number' => 'DLT-REC-48V-001',
                'mac_address' => null,
                'ip_management' => '10.10.1.5',
                'rack_position' => 'Rack 1 - Unit U04-U06',
                'power_source' => 'PLN 3-Phase + ATS',
                'status' => 'active',
                'installed_at' => Carbon::now()->subMonths(6),
                'installed_by' => 'Teknisi Daya',
                'last_checked_at' => Carbon::now()->subDays(3),
                'specifications' => json_encode([
                    'outputVoltage' => '54.5V DC',
                    'capacityAmpere' => '50A',
                    'batteryChannels' => 2,
                ]),
                'notes' => 'Power supply DC untuk OLT dan Switch Core.',
            ],
            [
                'id' => 'DEV-POP-SDA-04',
                'network_pop_id' => 'POP-SDA-01',
                'category' => 'Baterai Bank',
                'brand' => 'Shoto',
                'model' => 'Shoto 48V 100Ah Lithium LiFePO4',
                'serial_number' => 'SHT-LFP-48100-77',
                'mac_address' => null,
                'ip_management' => null,
                'rack_position' => 'Rack 1 - Unit U01-U03',
                'power_source' => 'Delta Rectifier Bus',
                'status' => 'active',
                'installed_at' => Carbon::now()->subMonths(6),
                'installed_by' => 'Teknisi Daya',
                'last_checked_at' => Carbon::now()->subDays(3),
                'specifications' => json_encode([
                    'nominalVoltage' => '48V',
                    'capacityAh' => 100,
                    'healthStatePercent' => 98,
                ]),
                'notes' => 'Baterai cadangan durasi tahan 8 jam saat PLN padam.',
            ],
            // Devices for POP-WAR-01
            [
                'id' => 'DEV-POP-WAR-01',
                'network_pop_id' => 'POP-WAR-01',
                'category' => 'OLT',
                'brand' => 'Huawei',
                'model' => 'SmartAX MA5608T Mini OLT',
                'serial_number' => 'HW-MA5608T-WAR-101',
                'mac_address' => 'F8:4A:BF:11:90:33',
                'ip_management' => '10.10.2.10',
                'rack_position' => 'Rack 1 - Unit U10-U12',
                'power_source' => 'Rectifier 48V Port 1',
                'status' => 'active',
                'installed_at' => Carbon::now()->subMonths(4),
                'installed_by' => 'Bambang Irawan (Teknisi)',
                'last_checked_at' => Carbon::now()->subDays(5),
                'specifications' => json_encode([
                    'totalPonPorts' => 8,
                    'usedPonPorts' => 6,
                    'firmwareVersion' => 'V800R018',
                ]),
                'notes' => 'OLT distribusi cluster Waru dan Tropodo.',
            ],
            [
                'id' => 'DEV-POP-WAR-02',
                'network_pop_id' => 'POP-WAR-01',
                'category' => 'Switch Distribution',
                'brand' => 'Huawei',
                'model' => 'CloudEngine S5735-L24T4S-A-V2',
                'serial_number' => 'HW-S5735-WAR-882',
                'mac_address' => '70:7B:E8:22:91:02',
                'ip_management' => '10.10.2.2',
                'rack_position' => 'Rack 1 - Unit U14',
                'power_source' => 'AC 220V UPS Backup',
                'status' => 'active',
                'installed_at' => Carbon::now()->subMonths(4),
                'installed_by' => 'Ahmad Fauzi (NOC)',
                'last_checked_at' => Carbon::now()->subDays(4),
                'specifications' => json_encode([
                    'portsGigabit' => 24,
                    'sfpPorts' => 4,
                ]),
                'notes' => 'Switch distribusi lokal POP Waru.',
            ],
        ];

        foreach ($devices as $device) {
            DB::table('pop_devices')->updateOrInsert(
                ['id' => $device['id']],
                array_merge($device, ['created_at' => Carbon::now(), 'updated_at' => Carbon::now()])
            );
        }

        // 6. Sample Initial POP Work Orders (Demonstrating the NOC -> Lead Tech -> Field Tech -> NOC QC flow)
        $workOrders = [
            [
                'id' => 'WO-POP-2026-001',
                'network_pop_id' => 'POP-SDA-01',
                'action_type' => 'add_device',
                'title' => 'Penambahan SFP GPON C++ 8 Port di OLT ZTE C320 Slot 2',
                'description' => 'Ekspansi kapasitas pelanggan baru area Lemahputro. Pasang modul SFP Class C++ dan crosscheck optic output +5 s/d +7 dBm.',
                'priority' => 'high',
                'status' => 'completed',
                'target_device_id' => 'DEV-POP-SDA-01',
                'target_device_info' => json_encode([
                    'name' => 'ZTE ZXA10 C320 GPON',
                    'brand' => 'ZTE',
                    'model' => 'C320',
                ]),
                'new_device_payload' => json_encode([
                    'category' => 'SFP Module',
                    'brand' => 'ZTE / Hi-Optic',
                    'model' => 'SFP GPON OLT Class C++ 2.5G',
                    'serial_number' => 'SFP-CPP-2026-091',
                    'rackPosition' => 'Slot 2 Port 1-8',
                    'specifications' => ['txPowerDbm' => '+6.5 dBm', 'wavelength' => '1490nm Tx / 1310nm Rx'],
                ]),
                'materials_from_warehouse' => json_encode([
                    ['itemName' => 'SFP GPON OLT Class C++', 'quantity' => 2, 'unit' => 'Pcs'],
                    ['itemName' => 'Patchcord SC-UPC to SC-APC 3M', 'quantity' => 4, 'unit' => 'Pcs'],
                ]),
                'assigned_lead_name' => 'Supriyadi (Lead Tech)',
                'assigned_tech_id' => 'USR-07',
                'assigned_tech_name' => 'Bambang Irawan',
                'scheduled_date' => Carbon::now()->subDays(2)->format('Y-m-d 10:00'),
                'field_report' => json_encode([
                    'installedAt' => Carbon::now()->subDays(2)->format('Y-m-d 11:30'),
                    'rackUnit' => 'Rack 1 Unit U18',
                    'serialNumber' => 'SFP-CPP-2026-091',
                    'testResult' => 'Laser aktif, OPM terukur +6.4 dBm pada port 1-4.',
                    'technicianNotes' => 'Pemasangan rapi dan kabel patchcord ter-labeling.',
                    'photos' => ['storage/pop/sample_rack_installation.jpg'],
                ]),
                'noc_instruction' => json_encode([
                    'vlan' => 210,
                    'configurationGuide' => 'Konfigurasi interface gpon-olt_1/2/1 s/d 1/2/4 di ZTE C320.',
                ]),
                'noc_qc_result' => json_encode([
                    'verified' => true,
                    'verifiedBy' => 'Ahmad Fauzi (NOC)',
                    'verifiedAt' => Carbon::now()->subDays(2)->format('Y-m-d 13:00'),
                    'pingTestSuccess' => true,
                    'snmpActive' => true,
                    'rxTxPowerDbm' => '+6.4 dBm',
                    'qcNotes' => 'Verifikasi remote OLT sukses, SFP terdeteksi normal dan PON aktif.',
                ]),
                'warehouse_return_status' => 'none',
                'created_by' => 'Ahmad Fauzi (NOC)',
                'created_at' => Carbon::now()->subDays(3),
                'updated_at' => Carbon::now()->subDays(2),
            ],
            [
                'id' => 'WO-POP-2026-002',
                'network_pop_id' => 'POP-WAR-01',
                'action_type' => 'replace_device',
                'title' => 'Penggantian Rectifier 48V Rusak di POP Waru',
                'description' => 'Rectifier modul 1 mengalami overheat dan trip alarm. Ganti dengan unit Delta ESR-48/50D baru dari gudang.',
                'priority' => 'critical',
                'status' => 'waiting_noc_qc',
                'target_device_id' => null,
                'target_device_info' => json_encode([
                    'name' => 'Rectifier Modul Lama',
                    'brand' => 'Delta',
                    'model' => 'ESR-48/30A',
                ]),
                'new_device_payload' => json_encode([
                    'category' => 'Rectifier',
                    'brand' => 'Delta',
                    'model' => 'Delta ESR-48/50D Power System',
                    'serial_number' => 'DLT-REC-WAR-2026-08',
                    'rackPosition' => 'Rack 1 - Unit U04',
                    'specifications' => ['outputVoltage' => '54.2V DC', 'capacityAmpere' => '50A'],
                ]),
                'materials_from_warehouse' => json_encode([
                    ['itemName' => 'Rectifier Module Delta 48V 50A', 'quantity' => 1, 'unit' => 'Unit'],
                ]),
                'assigned_lead_name' => 'Supriyadi (Lead Tech)',
                'assigned_tech_id' => 'USR-07',
                'assigned_tech_name' => 'Bambang Irawan',
                'scheduled_date' => Carbon::now()->format('Y-m-d 09:00'),
                'field_report' => json_encode([
                    'installedAt' => Carbon::now()->format('Y-m-d 10:15'),
                    'rackUnit' => 'Rack 1 Unit U04',
                    'serialNumber' => 'DLT-REC-WAR-2026-08',
                    'testResult' => 'Tegangan DC output stabil 54.2V, pengisian baterai normal.',
                    'technicianNotes' => 'Rectifier lama dilepas dan siap diretur ke gudang.',
                    'photos' => ['storage/pop/rectifier_installed.jpg'],
                ]),
                'noc_instruction' => json_encode([
                    'configurationGuide' => 'Set float voltage 54.2V dan equalization 56.4V.',
                ]),
                'noc_qc_result' => null,
                'warehouse_return_status' => 'pending_qc',
                'created_by' => 'Ahmad Fauzi (NOC)',
                'created_at' => Carbon::now()->subHours(6),
                'updated_at' => Carbon::now()->subHours(1),
            ],
            [
                'id' => 'WO-POP-2026-003',
                'network_pop_id' => 'POP-KRN-01',
                'action_type' => 'add_device',
                'title' => 'Instalasi Switch Agregasi MikroTik CRS328 di POP Krian',
                'description' => 'Penambahan Switch distribusi 24-Port Gigabit + 4x SFP+ untuk pemisahan traffic pelanggan bisnis dan broadband Krian.',
                'priority' => 'medium',
                'status' => 'pending_lead_tech',
                'target_device_id' => null,
                'target_device_info' => null,
                'new_device_payload' => json_encode([
                    'category' => 'Switch Distribution',
                    'brand' => 'MikroTik',
                    'model' => 'CRS328-24P-4S+RM',
                    'serial_number' => 'MT-CRS328-KRN-019',
                    'ipManagement' => '10.10.3.5',
                    'rackPosition' => 'Rack 1 - Unit U15',
                    'specifications' => ['gigabitPorts' => 24, 'sfpPlusPorts' => 4, 'poeOut' => '802.3af/at'],
                ]),
                'materials_from_warehouse' => json_encode([
                    ['itemName' => 'MikroTik CRS328-24P-4S+RM', 'quantity' => 1, 'unit' => 'Unit'],
                    ['itemName' => 'Patchcord FO SC-LC Duplex 5M', 'quantity' => 2, 'unit' => 'Pcs'],
                ]),
                'assigned_lead_name' => 'Supriyadi (Lead Tech)',
                'assigned_tech_id' => null,
                'assigned_tech_name' => null,
                'scheduled_date' => Carbon::now()->addDay()->format('Y-m-d 09:30'),
                'field_report' => null,
                'noc_instruction' => json_encode([
                    'vlan' => 300,
                    'targetManagementIp' => '10.10.3.5/24',
                    'configurationGuide' => 'Aktifkan VLAN Trunking pada SFP+ 1 ke Core Router.',
                ]),
                'noc_qc_result' => null,
                'warehouse_return_status' => 'none',
                'created_by' => 'Ahmad Fauzi (NOC)',
                'created_at' => Carbon::now()->subHours(2),
                'updated_at' => Carbon::now()->subHours(2),
            ],
        ];

        foreach ($workOrders as $wo) {
            DB::table('pop_work_orders')->updateOrInsert(
                ['id' => $wo['id']],
                $wo
            );
        }
    }

    public function down(): void
    {
        RoleModuleMapping::query()->where('module_key', 'inventory_pop')->delete();
        AppNavigationModule::query()->where('module_key', 'inventory_pop')->delete();
        Schema::dropIfExists('pop_work_orders');
        Schema::dropIfExists('pop_devices');
        Schema::dropIfExists('network_pops');
    }
};
