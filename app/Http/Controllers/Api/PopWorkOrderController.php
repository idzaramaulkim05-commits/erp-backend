<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PopWorkOrderResource;
use App\Models\AuditLog;
use App\Models\NetworkPop;
use App\Models\PopDevice;
use App\Models\PopWorkOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PopWorkOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = PopWorkOrder::query()->with('pop')->latest();

        if ($request->filled('pop_id')) {
            $query->where('network_pop_id', $request->query('pop_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('action_type')) {
            $query->where('action_type', $request->query('action_type'));
        }

        if ($request->filled('tech_id')) {
            $query->where('assigned_tech_id', $request->query('tech_id'));
        }

        return PopWorkOrderResource::collection($query->get());
    }

    public function show(PopWorkOrder $popWorkOrder)
    {
        return PopWorkOrderResource::make($popWorkOrder->load('pop'));
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'network_pop_id' => ['required', 'string', 'exists:network_pops,id'],
            'action_type' => ['required', 'string', 'in:add_device,replace_device,modify_config,remove_device'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'target_device_id' => ['nullable', 'string', 'exists:pop_devices,id'],
            'new_device_payload' => ['nullable', 'array'],
            'materials_from_warehouse' => ['nullable', 'array'],
            'noc_instruction' => ['nullable', 'array'],
        ]);

        $pop = NetworkPop::query()->findOrFail($payload['network_pop_id']);

        $targetDeviceInfo = null;
        if (!empty($payload['target_device_id'])) {
            $targetDevice = PopDevice::query()->find($payload['target_device_id']);
            if ($targetDevice) {
                $targetDeviceInfo = [
                    'id' => $targetDevice->id,
                    'category' => $targetDevice->category,
                    'brand' => $targetDevice->brand,
                    'model' => $targetDevice->model,
                    'serialNumber' => $targetDevice->serial_number,
                    'macAddress' => $targetDevice->mac_address,
                    'rackPosition' => $targetDevice->rack_position,
                ];
            }
        }

        $id = 'WO-POP-' . date('Y') . '-' . sprintf('%03d', PopWorkOrder::query()->count() + 1);
        while (PopWorkOrder::query()->where('id', $id)->exists()) {
            $id = 'WO-POP-' . date('Y') . '-' . sprintf('%03d', random_int(100, 999));
        }

        $workOrder = PopWorkOrder::query()->create([
            'id' => $id,
            'network_pop_id' => $pop->id,
            'action_type' => $payload['action_type'],
            'title' => $payload['title'],
            'description' => $payload['description'],
            'priority' => $payload['priority'] ?? 'medium',
            'status' => 'pending_lead_tech',
            'target_device_id' => $payload['target_device_id'] ?? null,
            'target_device_info' => $targetDeviceInfo,
            'new_device_payload' => $payload['new_device_payload'] ?? [],
            'materials_from_warehouse' => $payload['materials_from_warehouse'] ?? [],
            'assigned_lead_name' => 'Supriyadi (Lead Tech)',
            'assigned_tech_id' => null,
            'assigned_tech_name' => null,
            'scheduled_date' => null,
            'field_report' => null,
            'noc_instruction' => array_merge($payload['noc_instruction'] ?? [], [
                'createdBy' => $request->user()->name,
                'createdAt' => Carbon::now()->format('Y-m-d H:i:s'),
            ]),
            'noc_qc_result' => null,
            'warehouse_return_status' => in_array($payload['action_type'], ['replace_device', 'remove_device'], true) ? 'pending_qc' : 'none',
            'created_by' => $request->user()->name . ' (' . ($request->user()->role_title ?? $request->user()->role) . ')',
        ]);

        $this->logAction($request->user(), 'Created POP Work Order', $workOrder->id, sprintf('NOC menginstruksikan pekerjaan POP: %s di %s', $workOrder->title, $pop->name), 'info');

        return PopWorkOrderResource::make($workOrder->load('pop'));
    }

    public function assignTech(Request $request, PopWorkOrder $popWorkOrder)
    {
        $payload = $request->validate([
            'assigned_tech_id' => ['required', 'string', 'exists:users,id'],
            'scheduled_date' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $tech = User::query()->findOrFail($payload['assigned_tech_id']);

        $popWorkOrder->update([
            'assigned_tech_id' => $tech->id,
            'assigned_tech_name' => $tech->name,
            'assigned_lead_name' => $request->user()->name,
            'scheduled_date' => $payload['scheduled_date'],
            'status' => 'assigned_to_tech',
        ]);

        $this->logAction($request->user(), 'Assigned POP Work Order', $popWorkOrder->id, sprintf('Kepala Teknisi menugaskan %s pada WO POP %s.', $tech->name, $popWorkOrder->id), 'info');

        return PopWorkOrderResource::make($popWorkOrder->load('pop'));
    }

    public function start(Request $request, PopWorkOrder $popWorkOrder)
    {
        $popWorkOrder->update([
            'status' => 'in_progress',
        ]);

        $this->logAction($request->user(), 'Started POP Work Order', $popWorkOrder->id, 'Teknisi memulai pengerjaan di lokasi POP.', 'info');

        return PopWorkOrderResource::make($popWorkOrder->load('pop'));
    }

    public function submitFieldReport(Request $request, PopWorkOrder $popWorkOrder)
    {
        $payload = $request->validate([
            'rack_unit' => ['required', 'string'],
            'serial_number' => ['nullable', 'string'],
            'mac_address' => ['nullable', 'string'],
            'ip_address' => ['nullable', 'string'],
            'test_result' => ['required', 'string'],
            'technician_notes' => ['nullable', 'string'],
            'photos' => ['nullable', 'array'],
        ]);

        $fieldReport = [
            'installedAt' => Carbon::now()->format('Y-m-d H:i:s'),
            'rackUnit' => $payload['rack_unit'],
            'serialNumber' => $payload['serial_number'] ?? data_get($popWorkOrder->new_device_payload, 'serialNumber'),
            'macAddress' => $payload['mac_address'] ?? data_get($popWorkOrder->new_device_payload, 'macAddress'),
            'ipAddress' => $payload['ip_address'] ?? data_get($popWorkOrder->new_device_payload, 'ipManagement'),
            'testResult' => $payload['test_result'],
            'technicianNotes' => $payload['technician_notes'] ?? null,
            'photos' => $payload['photos'] ?? [],
            'submittedBy' => $request->user()->name,
        ];

        $popWorkOrder->update([
            'field_report' => $fieldReport,
            'status' => 'waiting_noc_qc',
        ]);

        $this->logAction($request->user(), 'Submitted POP Field Report', $popWorkOrder->id, 'Teknisi menyelesaikan instalasi on-site dan mengirim laporan ke QC NOC.', 'warning');

        return PopWorkOrderResource::make($popWorkOrder->load('pop'));
    }

    public function nocQcApprove(Request $request, PopWorkOrder $popWorkOrder)
    {
        $payload = $request->validate([
            'ping_test_success' => ['required', 'boolean'],
            'snmp_active' => ['nullable', 'boolean'],
            'rx_tx_power_dbm' => ['nullable', 'string'],
            'qc_notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($request, $popWorkOrder, $payload) {
            $pop = $popWorkOrder->pop;
            $newDevicePayload = $popWorkOrder->new_device_payload ?? [];
            $fieldReport = $popWorkOrder->field_report ?? [];

            $qcResult = [
                'verified' => true,
                'verifiedBy' => $request->user()->name . ' (NOC)',
                'verifiedAt' => Carbon::now()->format('Y-m-d H:i:s'),
                'pingTestSuccess' => (bool) $payload['ping_test_success'],
                'snmpActive' => (bool) ($payload['snmp_active'] ?? true),
                'rxTxPowerDbm' => $payload['rx_tx_power_dbm'] ?? null,
                'qcNotes' => $payload['qc_notes'] ?? 'QC lulus pengujian teknis NOC.',
            ];

            // Auto-update POP Inventory based on action_type
            if ($popWorkOrder->action_type === 'add_device' || $popWorkOrder->action_type === 'replace_device') {
                $category = $newDevicePayload['category'] ?? 'Perangkat Jaringan';
                $brand = $newDevicePayload['brand'] ?? 'Generic';
                $model = $newDevicePayload['model'] ?? 'Device Model';
                $serialNumber = $fieldReport['serialNumber'] ?? $newDevicePayload['serialNumber'] ?? null;
                $macAddress = $fieldReport['macAddress'] ?? $newDevicePayload['macAddress'] ?? null;
                $ipManagement = $fieldReport['ipAddress'] ?? $newDevicePayload['ipManagement'] ?? null;
                $rackPosition = $fieldReport['rackUnit'] ?? $newDevicePayload['rackPosition'] ?? 'Rack 1';

                $deviceId = 'DEV-' . str_replace('POP-', '', $pop->id) . '-' . sprintf('%02d', $pop->devices()->count() + 1);
                while (PopDevice::query()->where('id', $deviceId)->exists()) {
                    $deviceId = 'DEV-' . str_replace('POP-', '', $pop->id) . '-' . sprintf('%02d', random_int(20, 99));
                }

                // If replacement, mark old target device as decommissioned / retur
                if ($popWorkOrder->action_type === 'replace_device' && $popWorkOrder->target_device_id) {
                    PopDevice::query()->whereKey($popWorkOrder->target_device_id)->update([
                        'status' => 'decommissioned',
                        'notes' => 'Digantikan oleh perangkat baru (' . $deviceId . ') melalui WO ' . $popWorkOrder->id,
                    ]);
                }

                // Insert the new device into the POP inventory
                PopDevice::query()->create([
                    'id' => $deviceId,
                    'network_pop_id' => $pop->id,
                    'category' => $category,
                    'brand' => $brand,
                    'model' => $model,
                    'serial_number' => $serialNumber,
                    'mac_address' => $macAddress,
                    'ip_management' => $ipManagement,
                    'rack_position' => $rackPosition,
                    'power_source' => $newDevicePayload['powerSource'] ?? 'Rectifier 48V',
                    'status' => 'active',
                    'installed_at' => Carbon::now(),
                    'installed_by' => $popWorkOrder->assigned_tech_name ?? $request->user()->name,
                    'last_checked_at' => Carbon::now(),
                    'specifications' => array_merge($newDevicePayload['specifications'] ?? [], [
                        'qcVerifiedBy' => $request->user()->name,
                        'qcVerifiedAt' => Carbon::now()->format('Y-m-d H:i:s'),
                        'rxTxPowerDbm' => $payload['rx_tx_power_dbm'] ?? null,
                    ]),
                    'notes' => 'Diinstalasi melalui penugasan POP ' . $popWorkOrder->id . '. ' . ($payload['qc_notes'] ?? ''),
                ]);
            } elseif ($popWorkOrder->action_type === 'remove_device' && $popWorkOrder->target_device_id) {
                PopDevice::query()->whereKey($popWorkOrder->target_device_id)->update([
                    'status' => 'decommissioned',
                    'notes' => 'Dicabut / dinonaktifkan melalui WO ' . $popWorkOrder->id,
                ]);
            } elseif ($popWorkOrder->action_type === 'modify_config' && $popWorkOrder->target_device_id) {
                $targetDevice = PopDevice::query()->find($popWorkOrder->target_device_id);
                if ($targetDevice) {
                    $targetDevice->update([
                        'rack_position' => $fieldReport['rackUnit'] ?? $targetDevice->rack_position,
                        'ip_management' => $fieldReport['ipAddress'] ?? $targetDevice->ip_management,
                        'last_checked_at' => Carbon::now(),
                        'notes' => $targetDevice->notes . ' (Dimodifikasi via ' . $popWorkOrder->id . ')',
                    ]);
                }
            }

            $popWorkOrder->update([
                'status' => 'completed',
                'noc_qc_result' => $qcResult,
            ]);

            $this->logAction($request->user(), 'NOC Approved POP Work Order', $popWorkOrder->id, sprintf('NOC menyetujui QC penugasan POP %s. Perangkat otomatis masuk/terupdate di Inventori POP %s.', $popWorkOrder->id, $pop->name), 'success');

            return PopWorkOrderResource::make($popWorkOrder->fresh()->load('pop'));
        });
    }

    public function nocQcReject(Request $request, PopWorkOrder $popWorkOrder)
    {
        $payload = $request->validate([
            'rejection_notes' => ['required', 'string'],
        ]);

        $popWorkOrder->update([
            'status' => 'rejected_by_noc',
            'noc_qc_result' => [
                'verified' => false,
                'rejectedBy' => $request->user()->name . ' (NOC)',
                'rejectedAt' => Carbon::now()->format('Y-m-d H:i:s'),
                'rejectionNotes' => $payload['rejection_notes'],
            ],
        ]);

        $this->logAction($request->user(), 'NOC Rejected POP Work Order', $popWorkOrder->id, sprintf('NOC mengembalikan penugasan POP %s: %s', $popWorkOrder->id, $payload['rejection_notes']), 'warning');

        return PopWorkOrderResource::make($popWorkOrder->fresh()->load('pop'));
    }

    private function logAction(User $actor, string $action, string $target, string $details, string $type = 'info'): void
    {
        AuditLog::query()->create([
            'id' => 'LOG-' . Str::upper(Str::random(8)),
            'timestamp' => Carbon::now(),
            'actor_name' => $actor->name,
            'actor_role' => $actor->role,
            'action' => $action,
            'target' => $target,
            'details' => $details,
            'type' => $type,
        ]);
    }
}
