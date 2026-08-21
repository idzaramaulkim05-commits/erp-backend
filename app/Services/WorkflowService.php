<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BillingRecord;
use App\Models\Customer;
use App\Models\InterDivisionTask;
use App\Models\InventoryItem;
use App\Models\NetworkOdp;
use App\Models\NetworkOdpPort;
use App\Models\ProcurementRequest;
use App\Models\ServiceRegistration;
use App\Models\StockMovement;
use App\Models\TroubleTicket;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowService
{
    public function createServiceRegistration(array $payload, User $actor): ServiceRegistration
    {
        $registration = ServiceRegistration::query()->create([
            'id' => $this->nextCode('SR', ServiceRegistration::query()->count() + 1),
            'name' => $payload['name'],
            'nik' => $payload['nik'],
            'phone' => $payload['phone'],
            'address' => $payload['address'],
            'region' => $payload['region'],
            'package_plan' => $payload['package_plan'],
            'monthly_fee' => $payload['monthly_fee'],
            'odp_id' => $payload['odp_id'],
            'status' => 'draft',
            'finance_status' => 'pending',
            'noc_status' => 'pending',
            'requested_by_id' => $actor->id,
            'meta' => ['created_by' => $actor->id],
        ]);

        $this->log($actor, 'Service Registration Drafted', $registration->id, 'Draft registrasi pasang baru dibuat oleh sales.', 'info');

        return $registration->fresh();
    }

    public function submitServiceRegistration(ServiceRegistration $registration, User $actor): ServiceRegistration
    {
        $registration->update([
            'status' => 'pending_finance',
            'finance_status' => 'pending',
        ]);

        $this->log($actor, 'Service Registration Submitted', $registration->id, 'Registrasi baru dikirim ke finance untuk direview.', 'warning');

        return $registration->fresh();
    }

    public function financeApproveServiceRegistration(ServiceRegistration $registration, User $actor, ?string $notes = null): ServiceRegistration
    {
        $registration->update([
            'status' => 'pending_noc',
            'finance_status' => 'approved',
            'finance_notes' => $notes,
            'finance_approved_by' => $actor->name,
            'finance_approved_at' => Carbon::now(),
        ]);

        $this->log($actor, 'Finance Approved Registration', $registration->id, $notes ?? 'Registrasi baru disetujui finance.', 'success');

        return $registration->fresh();
    }

    public function financeRejectServiceRegistration(ServiceRegistration $registration, User $actor, ?string $notes = null): ServiceRegistration
    {
        $registration->update([
            'status' => 'finance_rejected',
            'finance_status' => 'rejected',
            'finance_notes' => $notes,
            'finance_approved_by' => $actor->name,
            'finance_approved_at' => Carbon::now(),
        ]);

        $this->log($actor, 'Finance Rejected Registration', $registration->id, $notes ?? 'Registrasi baru ditolak finance.', 'warning');

        return $registration->fresh();
    }

    public function generateServiceRegistrationPppoe(ServiceRegistration $registration, User $actor): ServiceRegistration
    {
        $username = $registration->pppoe_username ?: strtolower(str_replace('-', '', $registration->id)).'@isp.net';
        $password = $registration->pppoe_password ?: str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);

        $registration->update([
            'pppoe_username' => $username,
            'pppoe_password' => $password,
            'generated_at' => Carbon::now(),
        ]);

        $this->log($actor, 'Generated PPPoE Draft', $registration->id, 'Username dan password PPPoE internal berhasil dibuat.', 'info');

        return $registration->fresh();
    }

    public function nocApproveServiceRegistration(ServiceRegistration $registration, User $actor, ?string $notes = null, ?int $portCandidate = null): ServiceRegistration
    {
        return DB::transaction(function () use ($registration, $actor, $notes, $portCandidate) {
            $registration = $this->generateServiceRegistrationPppoe($registration, $actor);
            $odp = NetworkOdp::query()->with('ports')->findOrFail($registration->odp_id);

            $port = $portCandidate
                ? $odp->ports()->where('port_number', $portCandidate)->where('status', 'empty')->first()
                : $odp->ports()->where('status', 'empty')->orderBy('port_number')->first();

            abort_unless($port, 422, 'ODP tidak memiliki port kosong untuk registrasi ini.');

            $registration->update([
                'status' => 'noc_approved',
                'noc_status' => 'approved',
                'noc_notes' => $notes,
                'noc_approved_by' => $actor->name,
                'noc_approved_at' => Carbon::now(),
                'odp_port_candidate' => $port->port_number,
            ]);

            $this->log($actor, 'NOC Approved Registration', $registration->id, $notes ?? 'Registrasi baru lolos validasi teknis NOC.', 'success');

            return $registration->fresh();
        });
    }

    public function nocRejectServiceRegistration(ServiceRegistration $registration, User $actor, ?string $notes = null): ServiceRegistration
    {
        $registration->update([
            'status' => 'noc_rejected',
            'noc_status' => 'rejected',
            'noc_notes' => $notes,
            'noc_approved_by' => $actor->name,
            'noc_approved_at' => Carbon::now(),
        ]);

        $this->log($actor, 'NOC Rejected Registration', $registration->id, $notes ?? 'Registrasi baru ditolak NOC.', 'warning');

        return $registration->fresh();
    }

    public function createInstallationWorkOrderFromRegistration(ServiceRegistration $registration, User $actor): ServiceRegistration
    {
        return DB::transaction(function () use ($registration, $actor) {
            abort_unless($registration->finance_status === 'approved', 422, 'Registrasi belum lolos approval finance.');
            abort_unless($registration->noc_status === 'approved', 422, 'Registrasi belum lolos approval NOC.');

            if ($registration->work_order_id) {
                return $registration->fresh();
            }

            $odp = NetworkOdp::query()->with('ports')->findOrFail($registration->odp_id);
            $port = $odp->ports()
                ->where('status', 'empty')
                ->when($registration->odp_port_candidate, fn ($query) => $query->where('port_number', $registration->odp_port_candidate))
                ->orderBy('port_number')
                ->first();

            abort_unless($port, 422, 'Port ODP yang disetujui sudah tidak tersedia.');

            $customerId = $this->nextCode('CUST', Customer::query()->count() + 1042);
            $customer = Customer::query()->create([
                'id' => $customerId,
                'name' => $registration->name,
                'nik' => $registration->nik,
                'phone' => $registration->phone,
                'address' => $registration->address,
                'region' => $registration->region,
                'package_plan' => $registration->package_plan,
                'monthly_fee' => $registration->monthly_fee,
                'pppoe_username' => $registration->pppoe_username,
                'pppoe_password' => $registration->pppoe_password,
                'ip_address' => '10.20.'.random_int(10, 40).'.'.random_int(10, 200),
                'ont_brand' => 'ZTE',
                'ont_model' => 'ZTE F609 V3',
                'ont_serial_number' => 'ONT'.random_int(100000, 999999),
                'odc_id' => $odp->odc_id,
                'odp_id' => $odp->id,
                'odp_port' => $port->port_number,
                'fiber_core_color' => $odp->fiber_core_color,
                'optical_power_dbm' => -21.0,
                'status' => 'active',
                'billing_status' => 'pending',
                'billing_due_date' => Carbon::now()->addDays(10)->toDateString(),
                'installed_date' => Carbon::now()->toDateString(),
                'assigned_technician_id' => User::query()->where('role', 'field_tech')->value('id'),
                'meta' => ['created_by_registration' => $registration->id],
            ]);

            $port->update([
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'pppoe_username' => $customer->pppoe_username,
                'optical_power_dbm' => $customer->optical_power_dbm,
                'status' => 'active',
            ]);

            $odp->update([
                'used_ports' => $odp->ports()->whereIn('status', ['active', 'faulty'])->count(),
            ]);

            BillingRecord::query()->create([
                'customer_id' => $customer->id,
                'status' => $customer->billing_status,
                'amount' => $customer->monthly_fee,
                'due_date' => Carbon::parse($customer->billing_due_date)->toDateString(),
                'paid_at' => null,
                'notes' => 'Service registration conversion',
            ]);

            $workOrder = WorkOrder::query()->create([
                'id' => $this->nextCode('WO', WorkOrder::query()->count() + 400),
                'type' => 'installation',
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'address' => $customer->address,
                'region' => $customer->region,
                'odp_id' => $customer->odp_id,
                'assigned_lead' => User::query()->where('role', 'lead_tech')->value('name') ?? 'Lead Tech',
                'assigned_tech_id' => null,
                'assigned_tech_name' => null,
                'service_registration_id' => $registration->id,
                'status' => 'pending_lead_assignment',
                'scheduled_date' => Carbon::now()->addDay()->format('Y-m-d 09:00'),
                'package_plan' => $customer->package_plan,
                'required_materials' => [
                    ['itemName' => 'Modem ONT', 'quantity' => 1, 'unit' => 'Unit'],
                    ['itemName' => 'Patch Cord SC-UPC 3M', 'quantity' => 1, 'unit' => 'Pcs'],
                    ['itemName' => 'Drop Cable Fiber 1 Core', 'quantity' => 100, 'unit' => 'Meter'],
                ],
            ]);

            $registration->update([
                'status' => 'ready_for_dispatch',
                'customer_id' => $customer->id,
                'work_order_id' => $workOrder->id,
            ]);

            $this->log($actor, 'Created Installation Work Order', $registration->id, "WO {$workOrder->id} diterbitkan dari registrasi baru.", 'success');

            return $registration->fresh();
        });
    }

    public function registerCustomer(array $payload, User $actor): Customer
    {
        return DB::transaction(function () use ($payload, $actor) {
            $odp = NetworkOdp::query()->with('ports')->findOrFail($payload['odp_id']);
            $port = $odp->ports()->where('status', 'empty')->orderBy('port_number')->first();

            abort_unless($port, 422, 'ODP tidak memiliki port kosong.');

            $customerId = $this->nextCode('CUST', Customer::query()->count() + 1042);
            $pppoeUsername = strtolower(str_replace('-', '', $customerId)).'@isp.net';
            $pppoePassword = Str::random(8).'!@';

            $customer = Customer::query()->create([
                'id' => $customerId,
                'name' => $payload['name'],
                'nik' => $payload['nik'],
                'phone' => $payload['phone'],
                'address' => $payload['address'],
                'region' => $payload['region'],
                'package_plan' => $payload['package_plan'],
                'monthly_fee' => $payload['monthly_fee'],
                'pppoe_username' => $pppoeUsername,
                'pppoe_password' => $pppoePassword,
                'ip_address' => $payload['ip_address'] ?? '10.20.'.random_int(10, 40).'.'.random_int(10, 200),
                'ont_brand' => $payload['ont_brand'] ?? 'ZTE',
                'ont_model' => $payload['ont_model'] ?? 'ZTE F609 V3',
                'ont_serial_number' => $payload['ont_serial_number'] ?? 'ONT'.random_int(100000, 999999),
                'odc_id' => $odp->odc_id,
                'odp_id' => $odp->id,
                'odp_port' => $port->port_number,
                'fiber_core_color' => $payload['fiber_core_color'] ?? $odp->fiber_core_color,
                'optical_power_dbm' => $payload['optical_power_dbm'] ?? -21.0,
                'status' => 'active',
                'billing_status' => ! empty($payload['initial_deposit_paid']) ? 'paid' : 'pending',
                'billing_due_date' => Carbon::now()->addDays(10)->toDateString(),
                'ktp_image' => $payload['ktp_image'] ?? null,
                'installed_date' => Carbon::now()->toDateString(),
                'assigned_technician_id' => $payload['assigned_technician_id'] ?? User::query()->where('role', 'field_tech')->value('id'),
                'last_payment_date' => ! empty($payload['initial_deposit_paid']) ? Carbon::now()->toDateString() : null,
                'meta' => ['created_by' => $actor->id],
            ]);

            $port->update([
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'pppoe_username' => $customer->pppoe_username,
                'optical_power_dbm' => $customer->optical_power_dbm,
                'status' => 'active',
            ]);

            $odp->update([
                'used_ports' => $odp->ports()->whereIn('status', ['active', 'faulty'])->count(),
            ]);

            BillingRecord::query()->create([
                'customer_id' => $customer->id,
                'status' => $customer->billing_status,
                'amount' => $customer->monthly_fee,
                'due_date' => Carbon::parse($customer->billing_due_date)->toDateString(),
                'paid_at' => $customer->last_payment_date,
                'notes' => 'Initial registration',
            ]);

            WorkOrder::query()->create([
                'id' => $this->nextCode('WO', WorkOrder::query()->count() + 400),
                'type' => 'installation',
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'address' => $customer->address,
                'region' => $customer->region,
                'odp_id' => $customer->odp_id,
                'assigned_lead' => User::query()->where('role', 'lead_tech')->value('name') ?? 'Lead Tech',
                'assigned_tech_id' => $customer->assigned_technician_id,
                'assigned_tech_name' => optional($customer->assignedTechnician)->name,
                'status' => 'assigned',
                'scheduled_date' => Carbon::now()->addDay()->format('Y-m-d 09:00'),
                'package_plan' => $customer->package_plan,
                'required_materials' => [
                    ['itemName' => 'Modem ONT', 'quantity' => 1, 'unit' => 'Unit'],
                    ['itemName' => 'Patch Cord SC-UPC 3M', 'quantity' => 1, 'unit' => 'Pcs'],
                    ['itemName' => 'Drop Cable Fiber 1 Core', 'quantity' => 100, 'unit' => 'Meter'],
                ],
            ]);

            $this->log($actor, 'New Customer Registered', $customer->id, 'Registrasi pelanggan dan WO instalasi otomatis diterbitkan.', 'success');

            return $customer->fresh();
        });
    }

    public function updateCustomerStatus(Customer $customer, string $status, User $actor, ?string $notes = null): Customer
    {
        return DB::transaction(function () use ($customer, $status, $actor, $notes) {
            $customer->update([
                'status' => $status,
                'billing_status' => $status === 'unpaid' ? 'unpaid' : $customer->billing_status,
            ]);

            if (in_array($status, ['uninstal_pending', 'uninstalled'], true)) {
                WorkOrder::query()->create([
                    'id' => $this->nextCode('WO', WorkOrder::query()->count() + 500),
                    'type' => 'uninstallation',
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'customer_phone' => $customer->phone,
                    'address' => $customer->address,
                    'region' => $customer->region,
                    'odp_id' => $customer->odp_id,
                    'assigned_lead' => User::query()->where('role', 'lead_tech')->value('name') ?? 'Lead Tech',
                    'assigned_tech_id' => $customer->assigned_technician_id,
                    'assigned_tech_name' => optional($customer->assignedTechnician)->name,
                    'status' => 'pending',
                    'scheduled_date' => Carbon::now()->addDay()->format('Y-m-d 10:00'),
                    'required_materials' => [],
                ]);
            }

            $this->log($actor, 'Updated Customer Status', $customer->id, trim("Status {$status}. ".($notes ?? '')), 'info');

            return $customer->fresh();
        });
    }

    public function createTicket(array $payload, User $actor): TroubleTicket
    {
        $customer = Customer::query()->findOrFail($payload['customer_id']);
        $category = $payload['category'];
        $physical = in_array($category, ['los_red_light', 'relocation', 'uninstallation'], true);

        $ticket = TroubleTicket::query()->create([
            'id' => $this->nextCode('TKT', TroubleTicket::query()->count() + 880),
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_address' => $customer->address,
            'region' => $customer->region,
            'odp_id' => $customer->odp_id,
            'category' => $category,
            'title' => $payload['title'],
            'description' => $payload['description'],
            'priority' => $payload['priority'],
            'status' => 'in_noc_review',
            'created_by' => $actor->name.' ('.$actor->role_title.')',
            'can_be_resolved_remotely' => ! $physical,
        ]);

        $this->log($actor, 'Trouble Ticket Created', $ticket->id, 'Helpdesk membuat tiket baru dan meneruskannya ke NOC.', 'warning');

        return $ticket;
    }

    public function resolveTicketRemotely(TroubleTicket $ticket, User $actor, string $notes): TroubleTicket
    {
        $ticket->update([
            'status' => 'closed',
            'noc_diagnostic_notes' => $notes,
            'noc_final_verification' => [
                'verified' => true,
                'verifiedBy' => $actor->name,
                'verifiedAt' => Carbon::now()->format('Y-m-d H:i:s'),
                'opticalDbmReading' => -20.0,
                'pppoeSessionActive' => true,
                'rxPowerThresholdPassed' => true,
                'notes' => $notes,
            ],
        ]);

        $this->log($actor, 'Ticket Resolved Remotely', $ticket->id, $notes, 'success');

        return $ticket->fresh();
    }

    public function escalateTicket(TroubleTicket $ticket, User $actor, string $notes): TroubleTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $notes) {
            $ticket->update([
                'status' => 'assigned_to_lead',
                'noc_diagnostic_notes' => $notes,
                'assigned_to' => User::query()->where('role', 'lead_tech')->value('id'),
                'assigned_tech_name' => User::query()->where('role', 'lead_tech')->value('name'),
            ]);

            WorkOrder::query()->create([
                'id' => $this->nextCode('WO', WorkOrder::query()->count() + 600),
                'type' => 'maintenance',
                'customer_id' => $ticket->customer_id,
                'customer_name' => $ticket->customer_name,
                'customer_phone' => $ticket->customer_phone,
                'address' => $ticket->customer_address,
                'region' => $ticket->region,
                'odp_id' => $ticket->odp_id,
                'assigned_lead' => User::query()->where('role', 'lead_tech')->value('name') ?? 'Lead Tech',
                'ticket_id' => $ticket->id,
                'status' => 'pending',
                'scheduled_date' => Carbon::now()->addDay()->format('Y-m-d 13:00'),
                'required_materials' => [
                    ['itemName' => 'Patch Cord SC-UPC 3M', 'quantity' => 1, 'unit' => 'Pcs'],
                ],
            ]);

            $this->log($actor, 'Ticket Escalated to Lead Tech', $ticket->id, $notes, 'warning');

            return $ticket->fresh();
        });
    }

    public function assignWorkOrder(WorkOrder $workOrder, string $techId, User $actor): WorkOrder
    {
        $tech = User::query()->findOrFail($techId);

        $workOrder->update([
            'assigned_tech_id' => $tech->id,
            'assigned_tech_name' => $tech->name,
            'status' => 'assigned',
        ]);

        if ($workOrder->service_registration_id) {
            ServiceRegistration::query()
                ->whereKey($workOrder->service_registration_id)
                ->update(['status' => 'ready_for_dispatch']);
        }

        $this->log($actor, 'Assigned Work Order', $workOrder->id, "WO dialokasikan ke {$tech->name}.", 'info');

        return $workOrder->fresh();
    }

    public function submitFieldReport(WorkOrder $workOrder, array $report, User $actor): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $report, $actor) {
            $usedMaterials = $report['used_materials'] ?? [];

            foreach ($usedMaterials as $itemPayload) {
                $item = InventoryItem::query()->where('name', $itemPayload['itemName'])->first();
                if ($item) {
                    $item->decrement('stock_available', $itemPayload['quantity']);
                    $item->increment('stock_in_use', $itemPayload['quantity']);
                    StockMovement::query()->create([
                        'inventory_item_id' => $item->id,
                        'movement_type' => 'out',
                        'quantity' => $itemPayload['quantity'],
                        'reference_type' => 'work_order',
                        'reference_id' => $workOrder->id,
                        'notes' => 'Material digunakan teknisi lapangan.',
                    ]);
                }
            }

            $nextStatus = $workOrder->service_registration_id ? 'waiting_noc_activation' : 'sop_submitted';

            $workOrder->update([
                'status' => $nextStatus,
                'used_materials' => $usedMaterials,
                'photos' => [
                    'ktp' => $report['photo_ktp'] ?? null,
                    'opmReading' => $report['photo_optical_power_meter'] ?? null,
                    'installedDevice' => $report['photo_modem_installation'] ?? null,
                ],
            ]);

            if ($workOrder->ticket_id) {
                TroubleTicket::query()->whereKey($workOrder->ticket_id)->update([
                    'status' => 'field_progress',
                    'field_work_report' => [
                        'actionTaken' => $report['action_taken'],
                        'patchCordReplaced' => $report['patch_cord_replaced'] ?? false,
                        'dropCableLengthMeters' => $report['drop_cable_length_meters'] ?? null,
                        'finalOpticalPowerDbm' => $report['final_optical_power_dbm'],
                        'modemReplaced' => $report['modem_replaced'] ?? false,
                        'newOntSerialNumber' => $report['new_ont_serial_number'] ?? null,
                        'photoKtp' => $report['photo_ktp'] ?? null,
                        'photoOpticalPowerMeter' => $report['photo_optical_power_meter'] ?? null,
                        'photoModemInstallation' => $report['photo_modem_installation'] ?? null,
                        'completedAt' => Carbon::now()->format('Y-m-d H:i:s'),
                        'technicianSignature' => $report['signature'] ?? null,
                    ],
                ]);
            }

            if ($workOrder->service_registration_id) {
                ServiceRegistration::query()
                    ->whereKey($workOrder->service_registration_id)
                    ->update([
                        'status' => 'field_submitted',
                        'meta' => array_merge(
                            ServiceRegistration::query()->whereKey($workOrder->service_registration_id)->value('meta') ?? [],
                            ['last_field_report_at' => Carbon::now()->format('Y-m-d H:i:s')]
                        ),
                    ]);
            }

            $this->log($actor, 'Submitted Field Report', $workOrder->id, $report['action_taken'], 'success');

            return $workOrder->fresh();
        });
    }

    public function leadApprove(TroubleTicket $ticket, array $payload, User $actor): TroubleTicket
    {
        $ticket->update([
            'status' => 'lead_sop_approved',
            'lead_tech_approval' => [
                'approved' => true,
                'approvedBy' => $actor->name,
                'approvedAt' => Carbon::now()->format('Y-m-d H:i:s'),
                'sopChecklist' => $payload['sop_checklist'],
                'notes' => $payload['notes'] ?? null,
            ],
        ]);

        WorkOrder::query()->where('ticket_id', $ticket->id)->update([
            'status' => 'approved',
            'sop_verified_by_lead' => true,
        ]);

        $this->log($actor, 'Lead Tech SOP Approved', $ticket->id, $payload['notes'] ?? 'SOP approved.', 'success');

        return $ticket->fresh();
    }

    public function nocClose(TroubleTicket $ticket, array $payload, User $actor): TroubleTicket
    {
        $ticket->update([
            'status' => 'closed',
            'noc_final_verification' => [
                'verified' => true,
                'verifiedBy' => $actor->name,
                'verifiedAt' => Carbon::now()->format('Y-m-d H:i:s'),
                'opticalDbmReading' => $payload['optical_dbm_reading'],
                'pppoeSessionActive' => $payload['pppoe_session_active'],
                'rxPowerThresholdPassed' => $payload['rx_power_threshold_passed'],
                'notes' => $payload['notes'] ?? null,
            ],
        ]);

        WorkOrder::query()->where('ticket_id', $ticket->id)->update([
            'status' => 'completed',
            'noc_activated' => true,
            'completed_at' => Carbon::now(),
        ]);

        $this->log($actor, 'NOC Closed Ticket', $ticket->id, $payload['notes'] ?? 'Ticket closed.', 'success');

        return $ticket->fresh();
    }

    public function nocFinalVerifyInstallation(WorkOrder $workOrder, array $payload, User $actor): WorkOrder
    {
        abort_unless($workOrder->type === 'installation', 422, 'Final verify NOC hanya untuk work order instalasi.');

        return DB::transaction(function () use ($workOrder, $payload, $actor) {
            $workOrder->update([
                'status' => 'completed',
                'noc_activated' => true,
                'completed_at' => Carbon::now(),
                'final_verification' => [
                    'verified' => true,
                    'verifiedBy' => $actor->name,
                    'verifiedAt' => Carbon::now()->format('Y-m-d H:i:s'),
                    'opticalDbmReading' => $payload['optical_dbm_reading'],
                    'pppoeSessionActive' => $payload['pppoe_session_active'],
                    'rxPowerThresholdPassed' => $payload['rx_power_threshold_passed'],
                    'notes' => $payload['notes'] ?? null,
                ],
            ]);

            Customer::query()->whereKey($workOrder->customer_id)->update([
                'optical_power_dbm' => $payload['optical_dbm_reading'],
                'status' => 'active',
            ]);

            if ($workOrder->service_registration_id) {
                ServiceRegistration::query()->whereKey($workOrder->service_registration_id)->update([
                    'status' => 'completed',
                    'noc_status' => 'approved',
                    'noc_notes' => $payload['notes'] ?? null,
                ]);
            }

            $this->log($actor, 'NOC Final Verified Installation', $workOrder->id, $payload['notes'] ?? 'Instalasi selesai diverifikasi NOC.', 'success');

            return $workOrder->fresh();
        });
    }

    public function createProcurement(array $payload, User $actor): ProcurementRequest
    {
        $total = (int) $payload['quantity'] * (int) $payload['unit_price'];

        $request = ProcurementRequest::query()->create([
            'id' => $this->nextCode('REQ', ProcurementRequest::query()->count() + 30),
            'item_code' => $payload['item_code'],
            'item_name' => $payload['item_name'],
            'quantity' => $payload['quantity'],
            'unit' => $payload['unit'],
            'unit_price' => $payload['unit_price'],
            'total_amount' => $total,
            'reason' => $payload['reason'],
            'requested_by' => $actor->name,
            'requested_at' => Carbon::now(),
            'status' => 'pending_finance',
        ]);

        $this->log($actor, 'Procurement Requested', $request->id, $payload['reason'], 'warning');

        return $request;
    }

    public function financeApprove(ProcurementRequest $request, User $actor, ?string $notes = null): ProcurementRequest
    {
        $requiresManagement = $request->total_amount > config('ioms.procurement_management_threshold');
        $request->update([
            'status' => $requiresManagement ? 'pending_management' : 'approved',
            'finance_approval' => [
                'approved' => true,
                'by' => $actor->name,
                'at' => Carbon::now()->format('Y-m-d H:i:s'),
                'requiresManagementApproval' => $requiresManagement,
                'notes' => $notes,
            ],
        ]);

        $this->log($actor, 'Finance Approved Procurement', $request->id, $notes ?? 'Approved by finance.', 'success');

        return $request->fresh();
    }

    public function managementApprove(ProcurementRequest $request, User $actor, ?string $notes = null): ProcurementRequest
    {
        $request->update([
            'status' => 'approved',
            'management_approval' => [
                'approved' => true,
                'by' => $actor->name,
                'at' => Carbon::now()->format('Y-m-d H:i:s'),
                'notes' => $notes,
            ],
        ]);

        $this->log($actor, 'Management Approved Procurement', $request->id, $notes ?? 'Approved by management.', 'success');

        return $request->fresh();
    }

    public function receiveProcurement(ProcurementRequest $request, User $actor): ProcurementRequest
    {
        return DB::transaction(function () use ($request, $actor) {
            $inventory = InventoryItem::query()->firstOrCreate(
                ['code' => $request->item_code],
                [
                    'id' => 'INV-'.Str::upper(Str::random(6)),
                    'name' => $request->item_name,
                    'category' => 'ONT',
                    'brand' => 'Generic',
                    'model' => $request->item_name,
                    'unit' => $request->unit,
                    'unit_price' => $request->unit_price,
                    'location_rack' => 'Gudang Utama',
                ]
            );

            $inventory->increment('stock_available', $request->quantity);
            StockMovement::query()->create([
                'inventory_item_id' => $inventory->id,
                'movement_type' => 'in',
                'quantity' => $request->quantity,
                'reference_type' => 'procurement',
                'reference_id' => $request->id,
                'notes' => 'Barang diterima dari procurement.',
            ]);

            $request->update([
                'status' => 'received',
                'received_at' => Carbon::now(),
            ]);

            $this->log($actor, 'Procurement Received', $request->id, 'Stok gudang bertambah.', 'success');

            return $request->fresh();
        });
    }

    public function createTask(array $payload, User $actor): InterDivisionTask
    {
        $task = InterDivisionTask::query()->create([
            'id' => $this->nextCode('TASK', InterDivisionTask::query()->count() + 90),
            'title' => $payload['title'],
            'description' => $payload['description'],
            'from_division' => $payload['from_division'],
            'to_division' => $payload['to_division'],
            'priority' => $payload['priority'],
            'status' => $payload['status'] ?? 'todo',
            'related_customer_id' => $payload['related_customer_id'] ?? null,
            'related_ticket_id' => $payload['related_ticket_id'] ?? null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'due_date' => Carbon::parse($payload['due_date']),
            'created_by' => $actor->name,
            'assigned_to' => $payload['assigned_to'] ?? null,
            'resolution_notes' => $payload['resolution_notes'] ?? null,
        ]);

        $this->log($actor, 'Task Created', $task->id, $task->title, 'info');

        return $task;
    }

    public function updateTaskStatus(InterDivisionTask $task, string $status, User $actor, ?string $notes = null): InterDivisionTask
    {
        $task->update([
            'status' => $status,
            'resolution_notes' => $notes ?? $task->resolution_notes,
            'updated_at' => Carbon::now(),
        ]);

        $this->log($actor, 'Task Status Updated', $task->id, trim("Status {$status}. ".($notes ?? '')), 'info');

        return $task->fresh();
    }

    public function log(User $actor, string $action, string $target, string $details, string $type = 'info'): void
    {
        AuditLog::query()->create([
            'id' => 'LOG-'.Str::upper(Str::random(8)),
            'timestamp' => Carbon::now(),
            'actor_name' => $actor->name,
            'actor_role' => $actor->role,
            'action' => $action,
            'target' => $target,
            'details' => $details,
            'type' => $type,
        ]);
    }

    private function nextCode(string $prefix, int $number): string
    {
        return $prefix.'-'.$number;
    }
}
