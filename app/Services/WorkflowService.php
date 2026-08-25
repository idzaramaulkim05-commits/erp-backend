<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BillingRecord;
use App\Models\Customer;
use App\Models\FinanceMutation;
use App\Models\InstallationMaterialRequest;
use App\Models\InterDivisionTask;
use App\Models\InventoryItem;
use App\Models\InventorySerial;
use App\Models\NetworkOdp;
use App\Models\ProcurementRequest;
use App\Models\ReimbursementRequest;
use App\Models\ServiceRegistration;
use App\Models\StockMovement;
use App\Models\TroubleTicket;
use App\Models\User;
use App\Models\WarehouseReturnRequest;
use App\Models\WorkOrder;
use Illuminate\Http\UploadedFile;
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
            'gender' => $payload['gender'],
            'phone' => $payload['phone'],
            'address' => $payload['address'],
            'region' => $payload['region'],
            'package_plan' => $payload['package_plan'],
            'monthly_fee' => $payload['monthly_fee'],
            'installation_fee' => $payload['installation_fee'] ?? 0,
            'odp_id' => $payload['odp_id'] ?? null,
            'entry_source' => $payload['entry_source'] ?? 'internal',
            'share_location_url' => $payload['share_location_url'] ?? null,
            'house_photo' => $payload['house_photo'] ?? null,
            'status' => 'draft',
            'validation_status' => 'draft',
            'survey_status' => 'pending',
            'finance_status' => 'pending',
            'noc_status' => 'pending',
            'requested_by_id' => $actor->id,
            'meta' => ['created_by' => $actor->id],
        ]);

        $this->log($actor, 'Service Registration Drafted', $registration->id, 'Draft registrasi pelanggan baru dibuat.', 'info');

        return $registration->fresh();
    }

    public function submitServiceRegistration(ServiceRegistration $registration, User $actor): ServiceRegistration
    {
        $registration->update([
            'status' => 'menunggu_validasi',
            'validation_status' => 'pending',
            'finance_status' => 'pending',
        ]);

        $this->log($actor, 'Service Registration Submitted', $registration->id, 'Registrasi baru dikirim ke antrean validasi awal.', 'warning');

        return $registration->fresh();
    }

    public function validateServiceRegistration(ServiceRegistration $registration, array $payload, User $actor): ServiceRegistration
    {
        $isValid = (bool) ($payload['is_valid'] ?? false);

        $registration->update([
            'status' => $isValid ? 'menunggu_survey' : 'perlu_perbaikan_data',
            'validation_status' => $isValid ? 'approved' : 'needs_revision',
            'validation_notes' => $payload['notes'] ?? null,
            'validated_by' => $actor->name,
            'validated_at' => Carbon::now(),
        ]);

        $this->log(
            $actor,
            $isValid ? 'Registration Validated' : 'Registration Needs Revision',
            $registration->id,
            $payload['notes'] ?? ($isValid ? 'Registrasi lanjut ke survey.' : 'Registrasi dikembalikan untuk dilengkapi.'),
            $isValid ? 'success' : 'warning',
        );

        return $registration->fresh();
    }

    public function surveyServiceRegistration(ServiceRegistration $registration, array $payload, User $actor): ServiceRegistration
    {
        return DB::transaction(function () use ($registration, $payload, $actor) {
            $requiredMaterials = collect($payload['required_materials'] ?? [])
                ->map(fn (array $item) => [
                    'itemName' => trim((string) ($item['itemName'] ?? '')),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'unit' => trim((string) ($item['unit'] ?? '')),
                ])
                ->filter(fn (array $item) => $item['itemName'] !== '' && $item['quantity'] > 0 && $item['unit'] !== '')
                ->values()
                ->all();

            if ($payload['result'] === 'layak') {
                abort_if(empty($requiredMaterials), 422, 'Material wajib diisi untuk survey layak instalasi.');
                abort_if(
                    count($requiredMaterials) !== count($payload['required_materials'] ?? []),
                    422,
                    'Lengkapi semua baris material terlebih dahulu.'
                );
            }

            $surveyData = [
                'pathAvailable' => (bool) ($payload['path_available'] ?? false),
                'odpAvailable' => (bool) ($payload['odp_available'] ?? false),
                'recommendedTeam' => $payload['recommended_team'] ?? null,
                'requiredMaterials' => $requiredMaterials,
                'odpId' => $payload['odp_id'] ?? $registration->odp_id,
                'odpPortCandidate' => $payload['odp_port_candidate'] ?? $registration->odp_port_candidate,
            ];

            $registration->update([
                'odp_id' => $payload['odp_id'] ?? $registration->odp_id,
                'odp_port_candidate' => $payload['odp_port_candidate'] ?? $registration->odp_port_candidate,
                'status' => $payload['result'] === 'layak' ? 'survey_layak' : 'survey_tidak_layak',
                'survey_status' => 'completed',
                'survey_result' => $payload['result'],
                'survey_notes' => $payload['notes'] ?? null,
                'surveyed_by' => $actor->name,
                'surveyed_at' => Carbon::now(),
                'survey_data' => $surveyData,
            ]);

            $this->log(
                $actor,
                'Survey Service Registration',
                $registration->id,
                ($payload['notes'] ?? 'Survey instalasi diproses.').' Result: '.$payload['result'],
                $payload['result'] === 'layak' ? 'success' : 'warning',
            );

            if ($payload['result'] !== 'layak') {
                return $registration->fresh();
            }

            return $this->createInstallationWorkOrderFromRegistration($registration->fresh(), $actor);
        });
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
            if ($registration->odp_id) {
                $registration = $this->generateServiceRegistrationPppoe($registration, $actor);
                $odp = NetworkOdp::query()->with('ports')->findOrFail($registration->odp_id);

                $port = $portCandidate
                    ? $odp->ports()->where('port_number', $portCandidate)->where('status', 'empty')->first()
                    : $odp->ports()->where('status', 'empty')->orderBy('port_number')->first();

                abort_unless($port, 422, 'ODP tidak memiliki port kosong untuk registrasi ini.');

                $registration->update([
                    'odp_port_candidate' => $port->port_number,
                ]);
            }

            $registration->update([
                'status' => 'noc_approved',
                'noc_status' => 'approved',
                'noc_notes' => $notes,
                'noc_approved_by' => $actor->name,
                'noc_approved_at' => Carbon::now(),
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
            $surveyApprovedFlow = $registration->survey_result === 'layak';
            $legacyApprovedFlow = $registration->finance_status === 'approved' && $registration->noc_status === 'approved';

            abort_unless(
                $surveyApprovedFlow || $legacyApprovedFlow,
                422,
                'Registrasi belum siap dibuatkan WO instalasi. Pastikan survey layak atau approval lama sudah lengkap.'
            );

            if ($registration->work_order_id) {
                return $registration->fresh();
            }

            if (! $registration->pppoe_username || ! $registration->pppoe_password) {
                $registration = $this->generateServiceRegistrationPppoe($registration, $actor);
            }

            $odp = null;
            $port = null;
            $customer = null;

            if ($registration->odp_id) {
                $odp = NetworkOdp::query()->with('ports')->findOrFail($registration->odp_id);
                $port = $odp->ports()
                    ->where('status', 'empty')
                    ->when($registration->odp_port_candidate, fn ($query) => $query->where('port_number', $registration->odp_port_candidate))
                    ->orderBy('port_number')
                    ->first();
            }

            if ($odp && $port) {
                $serviceStartedAt = Carbon::now()->toDateString();
                $serviceActiveUntil = Carbon::now()->addDays(30)->toDateString();
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
                    'billing_due_date' => $serviceActiveUntil,
                    'service_started_at' => $serviceStartedAt,
                    'service_active_until' => $serviceActiveUntil,
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
                    'due_date' => Carbon::parse($customer->service_active_until ?? $customer->billing_due_date)->toDateString(),
                    'paid_at' => null,
                    'notes' => 'Service registration conversion',
                ]);
            }

            $surveyData = $registration->survey_data ?? [];
            $requiredMaterials = $surveyData['requiredMaterials'] ?? [];

            abort_if(
                empty($requiredMaterials),
                422,
                'Kebutuhan material instalasi belum diisi. Lengkapi material dari tahap NOC sebelum membuat WO instalasi.'
            );

            $workOrder = WorkOrder::query()->create([
                'id' => $this->nextCode('WO', WorkOrder::query()->count() + 400),
                'type' => 'installation',
                'customer_id' => $customer?->id,
                'customer_name' => $registration->name,
                'customer_phone' => $registration->phone,
                'address' => $registration->address,
                'region' => $registration->region,
                'odp_id' => $registration->odp_id,
                'share_location_url' => $registration->share_location_url,
                'house_photo' => $registration->house_photo,
                'assigned_lead' => User::query()->where('role', 'lead_tech')->value('name') ?? 'Lead Tech',
                'assigned_tech_id' => null,
                'assigned_tech_name' => null,
                'service_registration_id' => $registration->id,
                'status' => 'pending_lead_assignment',
                'scheduled_date' => Carbon::now()->addDay()->format('Y-m-d 09:00'),
                'package_plan' => $registration->package_plan,
                'installation_fee_actual' => (int) ($registration->installation_fee ?? 0),
                'installation_payment_method' => null,
                'installation_payment_status' => null,
                'installation_payment_customer_paid' => false,
                'customer_biodata_confirmed' => false,
                'router_sn' => null,
                'pppoe_request_status' => 'not_requested',
                'required_materials' => $requiredMaterials,
                'survey_snapshot' => $surveyData,
                'network_credentials' => [
                    'pppoeUsername' => $registration->pppoe_username,
                    'pppoePassword' => $registration->pppoe_password,
                    'vlan' => 'Belum ditetapkan',
                ],
            ]);

            $materialRequest = $this->createInstallationMaterialRequest($registration, $workOrder, $actor, $requiredMaterials);

            $workOrder->update([
                'installation_material_request_id' => $materialRequest->id,
            ]);

            $registration->update([
                'status' => 'siap_wo_instalasi',
                'customer_id' => $customer?->id,
                'work_order_id' => $workOrder->id,
                'installation_material_request_id' => $materialRequest->id,
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
            $serviceStartedAt = Carbon::now()->toDateString();
            $serviceActiveUntil = Carbon::now()->addDays(30)->toDateString();

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
                'billing_due_date' => $serviceActiveUntil,
                'service_started_at' => $serviceStartedAt,
                'service_active_until' => $serviceActiveUntil,
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
                'due_date' => Carbon::parse($customer->service_active_until ?? $customer->billing_due_date)->toDateString(),
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

            if ($status === 'uninstal_pending') {
                $this->createUninstallationOperationalFlow($customer->fresh(), $actor, $notes);
            }

            $this->log($actor, 'Updated Customer Status', $customer->id, trim("Status {$status}. ".($notes ?? '')), 'info');

            return $customer->fresh();
        });
    }

    public function recordCustomerPayment(Customer $customer, User $actor, ?string $notes = null, ?string $paidAt = null): Customer
    {
        return DB::transaction(function () use ($customer, $actor, $notes, $paidAt) {
            $paymentDate = $paidAt ? Carbon::parse($paidAt)->startOfDay() : Carbon::today();
            $currentActiveUntil = $customer->service_active_until
                ? Carbon::parse($customer->service_active_until)->startOfDay()
                : ($customer->billing_due_date ? Carbon::parse($customer->billing_due_date)->startOfDay() : null);
            $nextActiveUntil = $currentActiveUntil && $currentActiveUntil->greaterThanOrEqualTo($paymentDate)
                ? $currentActiveUntil->copy()->addDays(30)
                : $paymentDate->copy()->addDays(30);
            $serviceStartedAt = $customer->service_started_at
                ? Carbon::parse($customer->service_started_at)->toDateString()
                : ($customer->installed_date ? Carbon::parse($customer->installed_date)->toDateString() : $paymentDate->toDateString());

            $customer->update([
                'status' => $customer->status === 'uninstalled' ? $customer->status : 'active',
                'billing_status' => 'paid',
                'billing_due_date' => $nextActiveUntil->toDateString(),
                'service_started_at' => $serviceStartedAt,
                'service_active_until' => $nextActiveUntil->toDateString(),
                'last_payment_date' => $paymentDate->toDateString(),
            ]);

            BillingRecord::query()->create([
                'customer_id' => $customer->id,
                'status' => 'paid',
                'amount' => $customer->monthly_fee,
                'due_date' => $nextActiveUntil->toDateString(),
                'paid_at' => $paymentDate->toDateString(),
                'notes' => $notes ?: 'Perpanjangan masa aktif 30 hari.',
            ]);

            $this->log(
                $actor,
                'Recorded Customer Payment',
                $customer->id,
                trim(($notes ?: 'Pembayaran pelanggan dicatat.').' Masa aktif sampai '.$nextActiveUntil->toDateString().'.'),
                'success'
            );

            return $customer->fresh();
        });
    }

    public function createReimbursementDraft(array $payload, User $actor, ?UploadedFile $receipt = null): ReimbursementRequest
    {
        return DB::transaction(function () use ($payload, $actor, $receipt) {
            $normalizedItems = $this->normalizeReimbursementItems($payload['items'] ?? []);
            abort_if(empty($normalizedItems), 422, 'Tambahkan minimal satu item rembes.');

            $reimbursement = ReimbursementRequest::query()->create([
                'id' => $this->nextCode('RMB', ReimbursementRequest::query()->count() + 1),
                'requested_by_id' => $actor->id,
                'requester_role' => $actor->role,
                'requester_division' => $actor->division,
                'transaction_date' => $payload['transaction_date'],
                'description' => $payload['description'],
                'total_claim' => collect($normalizedItems)->sum('subtotal'),
                'status' => 'draft',
                'receipt_path' => $receipt?->store('reimbursements/receipts', 'public'),
            ]);

            $reimbursement->items()->createMany($normalizedItems);
            $this->log($actor, 'Reimbursement Draft Created', $reimbursement->id, $reimbursement->description, 'info');

            return $reimbursement->fresh();
        });
    }

    public function updateReimbursementDraft(ReimbursementRequest $reimbursement, array $payload, User $actor, ?UploadedFile $receipt = null): ReimbursementRequest
    {
        abort_unless(
            $actor->hasAnyRole(['superadmin']) || $reimbursement->requested_by_id === $actor->id,
            403,
            'Anda tidak berhak mengubah request rembes ini.'
        );
        abort_unless(
            in_array($reimbursement->status, ['draft', 'rejected'], true),
            422,
            'Hanya draft atau request yang ditolak yang bisa diubah.'
        );

        return DB::transaction(function () use ($reimbursement, $payload, $actor, $receipt) {
            $normalizedItems = $this->normalizeReimbursementItems($payload['items'] ?? []);
            abort_if(empty($normalizedItems), 422, 'Tambahkan minimal satu item rembes.');

            $reimbursement->update([
                'transaction_date' => $payload['transaction_date'],
                'description' => $payload['description'],
                'total_claim' => collect($normalizedItems)->sum('subtotal'),
                'receipt_path' => $receipt?->store('reimbursements/receipts', 'public') ?? $reimbursement->receipt_path,
            ]);

            $reimbursement->items()->delete();
            $reimbursement->items()->createMany($normalizedItems);
            $this->log($actor, 'Reimbursement Draft Updated', $reimbursement->id, $reimbursement->description, 'info');

            return $reimbursement->fresh();
        });
    }

    public function submitReimbursementRequest(ReimbursementRequest $reimbursement, User $actor): ReimbursementRequest
    {
        abort_unless(
            $actor->hasAnyRole(['superadmin']) || $reimbursement->requested_by_id === $actor->id,
            403,
            'Anda tidak berhak submit request rembes ini.'
        );
        abort_unless(
            in_array($reimbursement->status, ['draft', 'rejected'], true),
            422,
            'Status request rembes ini tidak bisa disubmit ulang.'
        );
        abort_if($reimbursement->items()->count() === 0, 422, 'Tambahkan item rembes terlebih dahulu.');

        $reimbursement->update([
            'status' => 'pending_finance',
            'submitted_at' => Carbon::now(),
        ]);

        $this->log($actor, 'Reimbursement Submitted', $reimbursement->id, 'Request rembes masuk antrean finance.', 'warning');

        return $reimbursement->fresh();
    }

    public function financeApproveReimbursement(ReimbursementRequest $reimbursement, User $actor, ?string $notes = null): ReimbursementRequest
    {
        abort_unless($reimbursement->status === 'pending_finance', 422, 'Request rembes ini tidak berada di antrean finance.');

        $reimbursement->update([
            'status' => 'approved',
            'finance_notes' => $notes,
            'finance_reviewed_by' => $actor->name,
            'finance_reviewed_at' => Carbon::now(),
            'approved_at' => Carbon::now(),
        ]);

        $this->log($actor, 'Reimbursement Approved by Finance', $reimbursement->id, $notes ?: 'Request rembes disetujui finance.', 'success');

        return $reimbursement->fresh();
    }

    public function financeRejectReimbursement(ReimbursementRequest $reimbursement, User $actor, string $notes): ReimbursementRequest
    {
        abort_unless($reimbursement->status === 'pending_finance', 422, 'Request rembes ini tidak berada di antrean finance.');

        $reimbursement->update([
            'status' => 'rejected',
            'finance_notes' => $notes,
            'finance_reviewed_by' => $actor->name,
            'finance_reviewed_at' => Carbon::now(),
        ]);

        $this->log($actor, 'Reimbursement Rejected by Finance', $reimbursement->id, $notes, 'warning');

        return $reimbursement->fresh();
    }

    public function forwardReimbursementToManagement(ReimbursementRequest $reimbursement, User $actor, string $notes): ReimbursementRequest
    {
        abort_unless($reimbursement->status === 'pending_finance', 422, 'Request rembes ini tidak berada di antrean finance.');

        $reimbursement->update([
            'status' => 'pending_management',
            'finance_notes' => $notes,
            'finance_reviewed_by' => $actor->name,
            'finance_reviewed_at' => Carbon::now(),
        ]);

        $this->log($actor, 'Reimbursement Forwarded to Management', $reimbursement->id, $notes, 'warning');

        return $reimbursement->fresh();
    }

    public function managementApproveReimbursement(ReimbursementRequest $reimbursement, User $actor, ?string $notes = null): ReimbursementRequest
    {
        abort_unless($reimbursement->status === 'pending_management', 422, 'Request rembes ini tidak berada di antrean management.');

        $reimbursement->update([
            'status' => 'approved',
            'management_notes' => $notes,
            'management_reviewed_by' => $actor->name,
            'management_reviewed_at' => Carbon::now(),
            'approved_at' => Carbon::now(),
        ]);

        $this->log($actor, 'Reimbursement Approved by Management', $reimbursement->id, $notes ?: 'Request rembes disetujui management.', 'success');

        return $reimbursement->fresh();
    }

    public function managementRejectReimbursement(ReimbursementRequest $reimbursement, User $actor, string $notes): ReimbursementRequest
    {
        abort_unless($reimbursement->status === 'pending_management', 422, 'Request rembes ini tidak berada di antrean management.');

        $reimbursement->update([
            'status' => 'rejected',
            'management_notes' => $notes,
            'management_reviewed_by' => $actor->name,
            'management_reviewed_at' => Carbon::now(),
        ]);

        $this->log($actor, 'Reimbursement Rejected by Management', $reimbursement->id, $notes, 'warning');

        return $reimbursement->fresh();
    }

    public function markReimbursementPaid(ReimbursementRequest $reimbursement, User $actor, ?string $notes = null): ReimbursementRequest
    {
        abort_unless($reimbursement->status === 'approved', 422, 'Hanya request rembes yang approved yang bisa dicairkan.');

        $reimbursement->update([
            'status' => 'paid',
            'finance_notes' => $notes ?: $reimbursement->finance_notes,
            'paid_by' => $actor->name,
            'paid_at' => Carbon::now(),
        ]);

        $this->log($actor, 'Reimbursement Marked Paid', $reimbursement->id, $notes ?: 'Dana rembes sudah dicairkan.', 'success');

        return $reimbursement->fresh();
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
            'status' => 'field_done_waiting_helpdesk_qc',
            'noc_diagnostic_notes' => $notes,
            'noc_final_verification' => [
                'verified' => true,
                'verifiedBy' => $actor->name,
                'verifiedAt' => Carbon::now()->format('Y-m-d H:i:s'),
                'opticalDbmReading' => -20.0,
                'pppoeSessionActive' => true,
                'rxPowerThresholdPassed' => true,
                'notes' => $notes,
                'handoffToHelpdeskQc' => true,
            ],
        ]);

        $this->log($actor, 'Ticket Resolved Remotely', $ticket->id, 'Remote fix selesai dan tiket dikembalikan ke Helpdesk QC. '.$notes, 'success');

        return $ticket->fresh();
    }

    public function escalateTicket(TroubleTicket $ticket, User $actor, string $notes, array $options = []): TroubleTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $notes, $options) {
            $leadTech = User::query()->where('role', 'lead_tech')->first();
            $requiresReplacementRequest = (bool) ($options['requires_replacement_request'] ?? false);
            $replacementItems = $this->normalizeWarehouseItems(
                $options['replacement_items'] ?? ($requiresReplacementRequest ? $this->defaultReplacementMaterials() : [])
            );

            $ticket->update([
                'status' => 'assigned_to_lead',
                'noc_diagnostic_notes' => $notes,
                'assigned_to' => $leadTech?->id,
                'assigned_tech_name' => $leadTech?->name,
                'replacement_context' => [
                    'requiresReplacementRequest' => $requiresReplacementRequest,
                    'requestedItems' => $replacementItems,
                    'outboundRequestStatus' => $requiresReplacementRequest ? 'menunggu_persetujuan_gudang' : null,
                    'warehouseReturnStatus' => $requiresReplacementRequest ? 'belum_retur' : null,
                    'holdTicketUntilWarehouseReturn' => $requiresReplacementRequest,
                ],
            ]);

            $workOrder = WorkOrder::query()->updateOrCreate(
                ['ticket_id' => $ticket->id],
                [
                    'id' => WorkOrder::query()->where('ticket_id', $ticket->id)->value('id') ?: $this->nextCode('WO', WorkOrder::query()->count() + 600),
                    'type' => 'maintenance',
                    'customer_id' => $ticket->customer_id,
                    'customer_name' => $ticket->customer_name,
                    'customer_phone' => $ticket->customer_phone,
                    'address' => $ticket->customer_address,
                    'region' => $ticket->region,
                    'odp_id' => $ticket->odp_id,
                    'assigned_lead' => $leadTech?->name ?? 'Lead Tech',
                    'status' => 'pending_lead_assignment',
                    'scheduled_date' => Carbon::now()->addDay()->format('Y-m-d 13:00'),
                    'required_materials' => $requiresReplacementRequest ? $replacementItems : [
                        ['itemName' => 'Patch Cord SC-UPC 3M', 'quantity' => 1, 'unit' => 'Pcs'],
                    ],
                    'maintenance_payload' => [
                        'replacementFlowActive' => $requiresReplacementRequest,
                        'replacementRecommendedByNoc' => $requiresReplacementRequest,
                        'replacementRequestedItems' => $replacementItems,
                    ],
                    'warehouse_return_status' => $requiresReplacementRequest ? 'belum_retur' : null,
                ],
            );

            if ($requiresReplacementRequest) {
                $materialRequest = $this->createMaintenanceMaterialRequest($ticket, $workOrder, $actor, $replacementItems);

                $workOrder->update([
                    'installation_material_request_id' => $materialRequest->id,
                ]);
            }

            $this->log(
                $actor,
                'Ticket Escalated to Lead Tech',
                $ticket->id,
                $notes.($requiresReplacementRequest ? ' Request alat replacement dibuat otomatis.' : ''),
                'warning'
            );

            return $ticket->fresh();
        });
    }

    public function assignWorkOrder(WorkOrder $workOrder, string $techId, User $actor): WorkOrder
    {
        $tech = User::query()->findOrFail($techId);
        $nextStatus = 'menunggu_konfirmasi_teknisi';

        $workOrder->update([
            'assigned_tech_id' => $tech->id,
            'assigned_tech_name' => $tech->name,
            'status' => $nextStatus,
        ]);

        if ($workOrder->service_registration_id) {
            ServiceRegistration::query()
                ->whereKey($workOrder->service_registration_id)
                ->update(['status' => 'siap_wo_instalasi']);
        }

        $this->log($actor, 'Assigned Work Order', $workOrder->id, "WO dialokasikan ke {$tech->name}.", 'info');

        return $workOrder->fresh();
    }

    public function confirmFieldAssignment(WorkOrder $workOrder, User $actor): WorkOrder
    {
        abort_unless($workOrder->status === 'menunggu_konfirmasi_teknisi', 422, 'WO ini tidak sedang menunggu konfirmasi teknisi.');
        abort_unless($workOrder->assigned_tech_id === $actor->id, 403, 'WO ini bukan assignment Anda.');

        $workOrder->update([
            'status' => 'assigned',
        ]);

        $this->log($actor, 'Confirmed Field Assignment', $workOrder->id, 'Teknisi lapangan mengonfirmasi penerimaan WO baru.', 'info');

        return $workOrder->fresh();
    }

    public function submitFieldReport(WorkOrder $workOrder, array $report, User $actor): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $report, $actor) {
            $installationPhotoPath = $report['photo_installation_result'] instanceof UploadedFile
                ? $report['photo_installation_result']->store('work-orders/installation-photos', 'public')
                : ($report['photo_installation_result'] ?? null);
            $usedMaterials = $report['used_materials'] ?? [];
            $returnItems = $this->normalizeWarehouseItems($report['return_items'] ?? []);
            $isInstallation = (bool) $workOrder->service_registration_id;
            $isUninstallation = $workOrder->type === 'uninstallation';
            $replacementApplied = (bool) ($report['device_replacement_applied'] ?? false);
            $fieldActionType = $report['field_action_type']
                ?? ($replacementApplied ? 'ganti_onu_router' : (
                    $isUninstallation
                        ? 'pencabutan_alat'
                        : ($workOrder->type === 'maintenance' ? 'tanpa_ganti_alat' : 'instalasi_baru')
                ));
            $isNewInstallation = $workOrder->type === 'installation';
            if ($isNewInstallation) {
                abort_if(
                    ! ($report['customer_biodata_confirmed'] ?? false),
                    422,
                    'Checklist konfirmasi biodata pelanggan wajib dicentang sebelum submit ke QC NOC.'
                );
                abort_if(
                    trim((string) ($report['router_sn'] ?? '')) === '',
                    422,
                    'SN router / ONU wajib diisi untuk pasang baru.'
                );
                abort_if(
                    ! is_string($installationPhotoPath) || trim($installationPhotoPath) === '',
                    422,
                    'Foto pemasangan wajib dilengkapi untuk pasang baru.'
                );
                abort_if(
                    ! in_array($report['installation_payment_method'] ?? null, ['tunai', 'transfer'], true),
                    422,
                    'Metode pembayaran biaya pemasangan wajib dipilih.'
                );
                abort_if(
                    ! ($report['installation_payment_customer_paid'] ?? false),
                    422,
                    'Konfirmasi pembayaran pelanggan di lapangan wajib diisi sebelum submit.'
                );
                abort_if(
                    $workOrder->pppoe_request_status !== 'approved',
                    422,
                    'Request PPPoE ke NOC harus disetujui terlebih dahulu sebelum submit hasil instalasi.'
                );
            }
            $replacementFlowActive = $workOrder->type === 'maintenance'
                && (
                    $replacementApplied
                    || ! empty($returnItems)
                    || (bool) data_get($workOrder->maintenance_payload, 'replacementFlowActive')
                    || (bool) data_get($workOrder->maintenance_payload, 'replacementRecommendedByNoc')
                    || (bool) data_get($workOrder->required_materials, '0.itemName')
                );
            $uninstallationReturnFlowActive = $isUninstallation;

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

            $nextStatus = $isUninstallation ? 'closed' : 'menunggu_qc_noc';
            $onuIdentity = $this->parseOnuIdentityCandidate($workOrder, $report);
            $networkCredentials = $isNewInstallation
                ? ($workOrder->network_credentials ?? [])
                : ($report['network_credentials'] ?? ($workOrder->network_credentials ?? []));
            $existingMaintenancePayload = $workOrder->maintenance_payload ?? [];
            $customer = $workOrder->customer_id ? Customer::query()->find($workOrder->customer_id) : null;
            $oldDeviceSnapshot = $customer ? [
                'ontBrand' => $customer->ont_brand,
                'ontModel' => $customer->ont_model,
                'ontSerialNumber' => $customer->ont_serial_number,
                'meta' => $customer->meta ?? [],
            ] : null;

            $workOrder->update([
                'status' => $nextStatus,
                'installation_fee_actual' => $isNewInstallation
                    ? (int) ($report['installation_fee_actual'] ?? ($workOrder->installation_fee_actual ?? data_get($workOrder->survey_snapshot, 'installationFee') ?? 0))
                    : $workOrder->installation_fee_actual,
                'installation_payment_method' => $isNewInstallation
                    ? ($report['installation_payment_method'] ?? $workOrder->installation_payment_method)
                    : $workOrder->installation_payment_method,
                'installation_payment_status' => $isNewInstallation
                    ? 'pending_finance'
                    : $workOrder->installation_payment_status,
                'installation_payment_customer_paid' => $isNewInstallation
                    ? (bool) ($report['installation_payment_customer_paid'] ?? false)
                    : $workOrder->installation_payment_customer_paid,
                'customer_biodata_confirmed' => $isNewInstallation
                    ? (bool) ($report['customer_biodata_confirmed'] ?? false)
                    : $workOrder->customer_biodata_confirmed,
                'router_sn' => $isNewInstallation
                    ? trim((string) ($report['router_sn'] ?? ''))
                    : $workOrder->router_sn,
                'used_materials' => $usedMaterials,
                'photos' => [
                    'ktp' => $report['photo_ktp'] ?? null,
                    'odp' => $report['photo_odp'] ?? null,
                    'opmReading' => $report['photo_optical_power_meter'] ?? null,
                    'installedDevice' => $report['photo_modem_installation'] ?? null,
                    'modemIdentity' => $report['photo_modem_identity'] ?? null,
                    'installationResult' => $installationPhotoPath,
                ],
                'activation_payload' => [
                    'actionTaken' => $report['action_taken'],
                    'fieldActionType' => $fieldActionType,
                    'rootCause' => $report['root_cause'] ?? null,
                    'progressSummary' => $report['progress_summary'] ?? null,
                    'resultSummary' => $report['result_summary'] ?? null,
                    'installationFeeActual' => $isNewInstallation ? (int) ($report['installation_fee_actual'] ?? 0) : null,
                    'installationPaymentMethod' => $isNewInstallation ? ($report['installation_payment_method'] ?? null) : null,
                    'installationPaymentCustomerPaid' => $isNewInstallation ? (bool) ($report['installation_payment_customer_paid'] ?? false) : null,
                    'customerBiodataConfirmed' => $isNewInstallation ? (bool) ($report['customer_biodata_confirmed'] ?? false) : null,
                    'routerSn' => $isNewInstallation ? trim((string) ($report['router_sn'] ?? '')) : null,
                    'removedItems' => $returnItems,
                    'signature' => $report['activation_signature'] ?? $report['signature'] ?? null,
                    'terms' => $report['activation_terms'] ?? null,
                    'submittedAt' => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                'onu_identity' => $onuIdentity,
                'network_credentials' => $networkCredentials,
                'maintenance_payload' => $workOrder->type !== 'installation'
                    ? array_merge($existingMaintenancePayload, [
                        'fieldActionType' => $fieldActionType,
                        'deviceReplacementApplied' => $replacementApplied,
                        'replacementFlowActive' => $replacementFlowActive,
                        'replacementSummary' => $report['action_taken'],
                        'oldDeviceSnapshot' => $oldDeviceSnapshot,
                        'newDeviceIdentity' => $replacementApplied ? [
                            'brand' => $report['device_brand'] ?? $customer?->ont_brand ?? 'ONU Replacement',
                            'model' => $report['device_model'] ?? $customer?->ont_model ?? 'Replacement Device',
                            'ponSn' => $onuIdentity['ponSn'] ?? null,
                            'serialNumber' => $onuIdentity['serialNumber'] ?? null,
                            'macAddress' => $onuIdentity['macAddress'] ?? null,
                        ] : null,
                        'returnItems' => $returnItems,
                        'warehouseReturnStatus' => ($replacementFlowActive || $uninstallationReturnFlowActive) ? 'menunggu_qc_gudang' : null,
                        'uninstallationFlowActive' => $uninstallationReturnFlowActive,
                    ])
                    : $workOrder->maintenance_payload,
                'warehouse_return_status' => ($workOrder->type === 'maintenance' && $replacementFlowActive) || $uninstallationReturnFlowActive ? 'menunggu_qc_gudang' : null,
                'qc_status' => 'pending',
                'qc_notes' => null,
                'completed_at' => $isUninstallation ? Carbon::now() : $workOrder->completed_at,
            ]);

            if ($workOrder->ticket_id) {
                TroubleTicket::query()->whereKey($workOrder->ticket_id)->update([
                    'status' => $isUninstallation ? 'menunggu_retur_gudang' : 'field_progress',
                    'field_work_report' => [
                        'actionTaken' => $report['action_taken'],
                        'fieldActionType' => $fieldActionType,
                        'rootCause' => $report['root_cause'] ?? null,
                        'progressSummary' => $report['progress_summary'] ?? null,
                        'resultSummary' => $report['result_summary'] ?? null,
                        'patchCordReplaced' => $report['patch_cord_replaced'] ?? false,
                        'dropCableLengthMeters' => $report['drop_cable_length_meters'] ?? null,
                        'finalOpticalPowerDbm' => $report['final_optical_power_dbm'],
                        'modemReplaced' => $replacementApplied || ($report['modem_replaced'] ?? false),
                        'newOntSerialNumber' => $report['new_ont_serial_number'] ?? null,
                        'photoKtp' => $report['photo_ktp'] ?? null,
                        'photoOpticalPowerMeter' => $report['photo_optical_power_meter'] ?? null,
                        'photoModemInstallation' => $report['photo_modem_installation'] ?? null,
                        'photoInstallationResult' => $installationPhotoPath,
                        'completedAt' => Carbon::now()->format('Y-m-d H:i:s'),
                        'technicianSignature' => $report['signature'] ?? null,
                        'returnItems' => $returnItems,
                        'deviceReplacementApplied' => $replacementApplied,
                    ],
                    'replacement_context' => $isUninstallation
                        ? array_merge(
                            TroubleTicket::query()->whereKey($workOrder->ticket_id)->value('replacement_context') ?? [],
                            [
                                'returnType' => 'uninstallation',
                                'warehouseReturnStatus' => 'menunggu_qc_gudang',
                                'holdTicketUntilWarehouseReturn' => true,
                            ]
                        )
                        : TroubleTicket::query()->whereKey($workOrder->ticket_id)->value('replacement_context'),
                ]);
            }

            if ($workOrder->type === 'maintenance' && $replacementApplied && $customer) {
                $customerMeta = $customer->meta ?? [];
                $deviceHistory = is_array($customerMeta['deviceReplacementHistory'] ?? null)
                    ? $customerMeta['deviceReplacementHistory']
                    : [];
                $deviceHistory[] = [
                    'replacedAt' => Carbon::now()->format('Y-m-d H:i:s'),
                    'workOrderId' => $workOrder->id,
                    'oldDevice' => $oldDeviceSnapshot,
                    'newDevice' => [
                        'brand' => $report['device_brand'] ?? $customer->ont_brand,
                        'model' => $report['device_model'] ?? $customer->ont_model,
                        'ontSerialNumber' => $onuIdentity['serialNumber'] ?? null,
                        'ponSn' => $onuIdentity['ponSn'] ?? null,
                        'macAddress' => $onuIdentity['macAddress'] ?? null,
                    ],
                ];

                $customer->update([
                    'ont_brand' => $report['device_brand'] ?? $customer->ont_brand,
                    'ont_model' => $report['device_model'] ?? $customer->ont_model,
                    'ont_serial_number' => $onuIdentity['serialNumber'] ?? $customer->ont_serial_number,
                    'meta' => array_merge($customerMeta, [
                        'deviceReplacementHistory' => $deviceHistory,
                        'activeDevicePON' => $onuIdentity['ponSn'] ?? null,
                        'activeDeviceMacAddress' => $onuIdentity['macAddress'] ?? null,
                    ]),
                ]);
            }

            if (($workOrder->type === 'maintenance' && $replacementFlowActive) || $isUninstallation) {
                $returnRequest = $this->createOrRefreshWarehouseReturnRequest(
                    $workOrder,
                    $actor,
                    $returnItems,
                    $replacementApplied,
                    $isUninstallation ? 'uninstallation' : 'replacement'
                );

                $workOrder->update([
                    'warehouse_return_request_id' => $returnRequest->id,
                    'warehouse_return_status' => $returnRequest->status,
                ]);
            }

            if ($workOrder->service_registration_id) {
                ServiceRegistration::query()
                    ->whereKey($workOrder->service_registration_id)
                    ->update([
                        'status' => 'menunggu_qc_noc',
                        'activation_report' => [
                            'actionTaken' => $report['action_taken'],
                            'finalOpticalPowerDbm' => $report['final_optical_power_dbm'],
                            'usedMaterials' => $usedMaterials,
                            'onuIdentity' => $onuIdentity,
                            'networkCredentials' => $networkCredentials,
                            'installationFeeActual' => $isNewInstallation ? (int) ($report['installation_fee_actual'] ?? 0) : null,
                            'installationPaymentMethod' => $isNewInstallation ? ($report['installation_payment_method'] ?? null) : null,
                            'installationPaymentCustomerPaid' => $isNewInstallation ? (bool) ($report['installation_payment_customer_paid'] ?? false) : null,
                            'customerBiodataConfirmed' => $isNewInstallation ? (bool) ($report['customer_biodata_confirmed'] ?? false) : null,
                            'routerSn' => $isNewInstallation ? trim((string) ($report['router_sn'] ?? '')) : null,
                        ],
                        'activation_document' => [
                            'signature' => $report['activation_signature'] ?? $report['signature'] ?? null,
                            'terms' => $report['activation_terms'] ?? null,
                            'signedAt' => Carbon::now()->format('Y-m-d H:i:s'),
                        ],
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

    public function helpdeskCloseTicket(TroubleTicket $ticket, array $payload, User $actor): TroubleTicket
    {
        abort_if(
            data_get($ticket->replacement_context, 'holdTicketUntilWarehouseReturn')
            && data_get($ticket->replacement_context, 'warehouseReturnStatus') !== 'retur_selesai',
            422,
            'Ticket ini masih menunggu QC retur gudang sebelum bisa ditutup.'
        );

        $finalVerification = $ticket->noc_final_verification ?? [];

        $ticket->update([
            'status' => 'closed',
            'noc_final_verification' => array_merge($finalVerification, [
                'helpdeskQcBy' => $actor->name,
                'helpdeskQcAt' => Carbon::now()->format('Y-m-d H:i:s'),
                'connectionNormal' => (bool) $payload['connection_normal'],
                'helpdeskNotes' => $payload['notes'],
            ]),
        ]);

        WorkOrder::query()->where('ticket_id', $ticket->id)->update([
            'status' => 'closed',
            'completed_at' => Carbon::now(),
        ]);

        $this->log($actor, 'Helpdesk Closed Ticket', $ticket->id, $payload['notes'], 'success');

        return $ticket->fresh();
    }

    public function startInstallationWorkOrder(WorkOrder $workOrder, User $actor): WorkOrder
    {
        abort_unless(in_array($workOrder->status, ['assigned', 'dikembalikan_ke_teknisi'], true), 422, 'WO harus dikonfirmasi lebih dulu sebelum mulai dikerjakan.');

        if ($actor->role === 'field_tech') {
            abort_unless($workOrder->assigned_tech_id === $actor->id, 403, 'WO ini bukan assignment Anda.');
        }

        $workOrder->update([
            'status' => $workOrder->type === 'installation' ? 'sedang_diinstal' : 'in_progress',
        ]);

        if ($workOrder->service_registration_id) {
            ServiceRegistration::query()
                ->whereKey($workOrder->service_registration_id)
                ->update(['status' => 'sedang_diinstal']);
        }

        if ($workOrder->ticket_id) {
            TroubleTicket::query()->whereKey($workOrder->ticket_id)->update([
                'status' => 'field_progress',
            ]);
        }

        $this->log($actor, 'Started Field Work Order', $workOrder->id, 'Teknisi memulai pekerjaan lapangan.', 'info');

        return $workOrder->fresh();
    }

    public function returnInstallationToTech(WorkOrder $workOrder, array $payload, User $actor): WorkOrder
    {
        abort_unless($workOrder->type === 'installation', 422, 'Pengembalian QC hanya untuk WO instalasi.');

        $workOrder->update([
            'status' => 'dikembalikan_ke_teknisi',
            'qc_status' => 'failed',
            'qc_notes' => $payload['notes'] ?? null,
            'returned_to_tech_at' => Carbon::now(),
        ]);

        if ($workOrder->service_registration_id) {
            ServiceRegistration::query()
                ->whereKey($workOrder->service_registration_id)
                ->update([
                    'status' => 'sedang_diinstal',
                    'noc_notes' => $payload['notes'] ?? null,
                ]);
        }

        $this->log($actor, 'Returned Installation to Tech', $workOrder->id, $payload['notes'] ?? 'QC NOC meminta revisi.', 'warning');

        return $workOrder->fresh();
    }

    public function nocFinalVerifyInstallation(WorkOrder $workOrder, array $payload, User $actor): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $payload, $actor) {
            $workOrder->update([
                'status' => 'closed',
                'noc_activated' => true,
                'completed_at' => Carbon::now(),
                'qc_status' => 'passed',
                'qc_notes' => $payload['notes'] ?? null,
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

            if ($workOrder->customer_id) {
                Customer::query()->whereKey($workOrder->customer_id)->update([
                    'optical_power_dbm' => $payload['optical_dbm_reading'],
                    'status' => 'active',
                ]);
            }

            if ($workOrder->type === 'installation' && $workOrder->service_registration_id) {
                ServiceRegistration::query()->whereKey($workOrder->service_registration_id)->update([
                    'status' => 'selesai',
                    'noc_status' => 'approved',
                    'noc_notes' => $payload['notes'] ?? null,
                ]);
            }

            if ($workOrder->type === 'maintenance' && $workOrder->ticket_id) {
                $replacementFlowActive = (bool) data_get($workOrder->maintenance_payload, 'replacementFlowActive');
                $ticketStatus = $replacementFlowActive ? 'menunggu_retur_gudang' : 'field_done_waiting_helpdesk_qc';
                $ticket = TroubleTicket::query()->find($workOrder->ticket_id);

                TroubleTicket::query()->whereKey($workOrder->ticket_id)->update([
                    'status' => $ticketStatus,
                    'noc_final_verification' => [
                        'verified' => true,
                        'verifiedBy' => $actor->name,
                        'verifiedAt' => Carbon::now()->format('Y-m-d H:i:s'),
                        'opticalDbmReading' => $payload['optical_dbm_reading'],
                        'pppoeSessionActive' => $payload['pppoe_session_active'],
                        'rxPowerThresholdPassed' => $payload['rx_power_threshold_passed'],
                        'notes' => $payload['notes'] ?? null,
                    ],
                    'replacement_context' => array_merge($ticket?->replacement_context ?? [], [
                        'requiresReplacementRequest' => $replacementFlowActive || (bool) data_get($ticket?->replacement_context, 'requiresReplacementRequest'),
                        'warehouseReturnStatus' => $replacementFlowActive ? 'menunggu_qc_gudang' : null,
                        'holdTicketUntilWarehouseReturn' => $replacementFlowActive,
                    ]),
                ]);
            }

            $this->log(
                $actor,
                $workOrder->type === 'installation' ? 'NOC Final Verified Installation' : 'NOC Final Verified Maintenance',
                $workOrder->id,
                $payload['notes'] ?? ($workOrder->type === 'installation'
                    ? 'Instalasi selesai diverifikasi NOC.'
                    : 'Gangguan lapangan selesai diverifikasi NOC.'),
                'success'
            );

            return $workOrder->fresh();
        });
    }

    public function requestInstallationPppoe(WorkOrder $workOrder, array $payload, User $actor): WorkOrder
    {
        abort_unless($workOrder->type === 'installation', 422, 'Request PPPoE hanya berlaku untuk WO pasang baru.');
        abort_unless($workOrder->assigned_tech_id === $actor->id || $actor->hasAnyRole(['superadmin']), 403, 'WO ini bukan assignment Anda.');
        abort_unless(
            in_array($workOrder->status, ['assigned', 'sedang_diinstal', 'in_progress', 'dikembalikan_ke_teknisi'], true),
            422,
            'WO ini belum berada pada tahap request PPPoE.'
        );

        $activationPayload = $workOrder->activation_payload ?? [];
        $activationPayload['pppoeRequestNotes'] = $payload['notes'] ?? null;

        $workOrder->update([
            'pppoe_request_status' => 'pending_noc',
            'pppoe_requested_at' => Carbon::now(),
            'pppoe_requested_by' => $actor->name,
            'activation_payload' => $activationPayload,
        ]);

        $this->log($actor, 'Requested PPPoE to NOC', $workOrder->id, $payload['notes'] ?? 'Teknisi meminta kredensial PPPoE ke NOC.', 'warning');

        return $workOrder->fresh();
    }

    public function approveInstallationPppoeRequest(WorkOrder $workOrder, array $payload, User $actor): WorkOrder
    {
        abort_unless($workOrder->type === 'installation', 422, 'Request PPPoE hanya berlaku untuk WO pasang baru.');
        abort_unless($workOrder->pppoe_request_status === 'pending_noc', 422, 'WO ini tidak sedang menunggu request PPPoE NOC.');

        $networkCredentials = array_merge($workOrder->network_credentials ?? [], [
            'pppoeUsername' => $payload['pppoe_username'],
            'pppoePassword' => $payload['pppoe_password'],
            'vlan' => $payload['vlan'] ?? data_get($workOrder->network_credentials, 'vlan'),
            'approvedByNocAt' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        $activationPayload = $workOrder->activation_payload ?? [];
        $activationPayload['pppoeApprovalNotes'] = $payload['notes'] ?? null;

        $workOrder->update([
            'network_credentials' => $networkCredentials,
            'pppoe_request_status' => 'approved',
            'pppoe_approved_at' => Carbon::now(),
            'pppoe_approved_by' => $actor->name,
            'activation_payload' => $activationPayload,
        ]);

        if ($workOrder->service_registration_id) {
            ServiceRegistration::query()->whereKey($workOrder->service_registration_id)->update([
                'pppoe_username' => $payload['pppoe_username'],
                'pppoe_password' => $payload['pppoe_password'],
                'generated_at' => Carbon::now(),
                'noc_notes' => $payload['notes'] ?? null,
            ]);
        }

        $this->log($actor, 'Approved PPPoE Request', $workOrder->id, $payload['notes'] ?? 'NOC mengisi kredensial PPPoE.', 'success');

        return $workOrder->fresh();
    }

    public function rejectInstallationPppoeRequest(WorkOrder $workOrder, array $payload, User $actor): WorkOrder
    {
        abort_unless($workOrder->type === 'installation', 422, 'Request PPPoE hanya berlaku untuk WO pasang baru.');
        abort_unless($workOrder->pppoe_request_status === 'pending_noc', 422, 'WO ini tidak sedang menunggu request PPPoE NOC.');

        $activationPayload = $workOrder->activation_payload ?? [];
        $activationPayload['pppoeRejectionNotes'] = $payload['notes'];

        $workOrder->update([
            'pppoe_request_status' => 'rejected',
            'activation_payload' => $activationPayload,
        ]);

        $this->log($actor, 'Rejected PPPoE Request', $workOrder->id, $payload['notes'], 'warning');

        return $workOrder->fresh();
    }

    public function confirmInstallationPayment(WorkOrder $workOrder, string $method, array $payload, User $actor): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $method, $payload, $actor) {
            abort_unless($workOrder->type === 'installation', 422, 'Konfirmasi ini hanya berlaku untuk WO pasang baru.');
            abort_unless($workOrder->installation_payment_method === $method, 422, 'Metode pembayaran WO ini tidak sesuai dengan antrean konfirmasi.');
            abort_unless($workOrder->installation_payment_status === 'pending_finance', 422, 'Pembayaran pemasangan ini tidak sedang menunggu konfirmasi finance.');
            abort_unless((bool) $workOrder->installation_payment_customer_paid, 422, 'Pembayaran pelanggan belum ditandai lunas di lapangan.');

            $category = $method === 'tunai' ? 'Biaya Pemasangan Tunai' : 'Biaya Pemasangan Transfer';

            FinanceMutation::query()->create([
                'id' => $this->nextCode('FM', FinanceMutation::query()->count() + 1),
                'transaction_date' => Carbon::today()->toDateString(),
                'type' => 'inflow',
                'category' => $category,
                'amount' => (int) ($workOrder->installation_fee_actual ?? 0),
                'description' => sprintf('Konfirmasi biaya pemasangan %s untuk %s.', $method, $workOrder->customer_name),
                'reference' => $workOrder->id,
                'status' => 'confirmed',
                'created_by_id' => $actor->id,
            ]);

            $workOrder->update([
                'installation_payment_status' => 'confirmed_finance',
                'installation_payment_confirmed_at' => Carbon::now(),
                'installation_payment_confirmed_by' => $actor->name,
                'installation_payment_notes' => $payload['notes'] ?? null,
            ]);

            $this->log($actor, 'Confirmed Installation Payment', $workOrder->id, $payload['notes'] ?? $category, 'success');

            return $workOrder->fresh();
        });
    }

    public function updateInstallationMaterialRequestStatus(InstallationMaterialRequest $request, array $payload, User $actor): InstallationMaterialRequest
    {
        $status = $payload['status'];

        $request->update([
            'status' => $status,
            'approval_notes' => $payload['approval_notes'] ?? null,
            'approved_by' => in_array($status, ['diproses_gudang', 'siap_diserahkan', 'ditolak'], true) ? $actor->name : $request->approved_by,
            'approved_at' => in_array($status, ['diproses_gudang', 'siap_diserahkan', 'ditolak'], true) ? Carbon::now() : $request->approved_at,
            'delivered_by' => $status === 'diserahkan_ke_teknisi' ? $actor->name : $request->delivered_by,
            'delivered_at' => $status === 'diserahkan_ke_teknisi' ? Carbon::now() : $request->delivered_at,
        ]);

        if ($request->ticket_id) {
            $ticket = TroubleTicket::query()->find($request->ticket_id);
            if ($ticket) {
                $ticket->update([
                    'replacement_context' => array_merge($ticket->replacement_context ?? [], [
                        'outboundRequestStatus' => $status,
                    ]),
                ]);
            }
        }

        $this->log($actor, 'Updated Installation Material Request', $request->id, 'Status request gudang: '.$status, 'info');

        return $request->fresh();
    }

    public function completeWarehouseReturnQc(WarehouseReturnRequest $returnRequest, ?string $notes, User $actor): WarehouseReturnRequest
    {
        return DB::transaction(function () use ($returnRequest, $notes, $actor) {
            abort_unless($returnRequest->status === 'menunggu_qc_gudang', 422, 'Retur gudang ini sudah diproses.');
            $returnType = $returnRequest->return_type ?? 'replacement';

            foreach (($returnRequest->items ?? []) as $itemPayload) {
                $itemName = (string) ($itemPayload['itemName'] ?? '');
                $item = InventoryItem::query()->where('name', $itemName)->first();

                if (! $item) {
                    continue;
                }

                $quantity = (int) ($itemPayload['quantity'] ?? 0);
                $returnCategory = (string) ($itemPayload['returnCategory'] ?? 'unused_replacement');

                if (in_array($returnCategory, ['old_defective', 'returned_damaged'], true)) {
                    $item->increment('stock_reserved', $quantity);
                    if ($item->stock_in_use > 0) {
                        $item->decrement('stock_in_use', min($quantity, $item->stock_in_use));
                    }
                } elseif ($returnCategory === 'missing') {
                    if ($item->stock_in_use > 0) {
                        $item->decrement('stock_in_use', min($quantity, $item->stock_in_use));
                    }
                } else {
                    $item->increment('stock_available', $quantity);
                    if ($item->stock_in_use > 0) {
                        $item->decrement('stock_in_use', min($quantity, $item->stock_in_use));
                    }
                }

                StockMovement::query()->create([
                    'inventory_item_id' => $item->id,
                    'movement_type' => 'return',
                    'quantity' => $quantity,
                    'reference_type' => 'warehouse_return_request',
                    'reference_id' => $returnRequest->id,
                    'notes' => $notes ?: 'Retur perangkat maintenance selesai di-QC gudang.',
                ]);

                if (! empty($itemPayload['serialNumbers']) && is_array($itemPayload['serialNumbers'])) {
                    InventorySerial::query()
                        ->where('inventory_item_id', $item->id)
                        ->whereIn('sn', $itemPayload['serialNumbers'])
                        ->update([
                            'status' => in_array($returnCategory, ['old_defective', 'returned_damaged', 'missing'], true) ? 'defective' : 'returned_reusable',
                            'assigned_tech' => null,
                        ]);
                }
            }

            $returnRequest->update([
                'status' => 'retur_selesai',
                'qc_notes' => $notes,
                'received_by' => $actor->name,
                'received_at' => Carbon::now(),
                'closed_at' => Carbon::now(),
            ]);

            $workOrder = WorkOrder::query()->find($returnRequest->work_order_id);

            if ($workOrder) {
                $workOrder->update([
                    'warehouse_return_status' => 'retur_selesai',
                ]);
            }

            if ($returnRequest->ticket_id) {
                TroubleTicket::query()->whereKey($returnRequest->ticket_id)->update([
                    'status' => 'closed',
                    'replacement_context' => array_merge(
                        TroubleTicket::query()->whereKey($returnRequest->ticket_id)->value('replacement_context') ?? [],
                        [
                            'returnType' => $returnType,
                            'warehouseReturnStatus' => 'retur_selesai',
                            'holdTicketUntilWarehouseReturn' => false,
                        ]
                    ),
                ]);
            }

            if ($returnType === 'uninstallation' && $returnRequest->customer_id) {
                Customer::query()->whereKey($returnRequest->customer_id)->update([
                    'status' => 'uninstalled',
                ]);
            }

            $this->log($actor, 'Warehouse Return QC Completed', $returnRequest->id, $notes ?? ($returnType === 'uninstallation' ? 'QC retur uninstall selesai dan ticket ditutup.' : 'QC retur gudang selesai dan ticket ditutup.'), 'success');

            return $returnRequest->fresh();
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

    public function updateProcurement(ProcurementRequest $request, array $payload, User $actor): ProcurementRequest
    {
        abort_unless($request->status === 'rejected', 422, 'Hanya request procurement yang ditolak yang bisa direvisi.');

        $total = (int) $payload['quantity'] * (int) $payload['unit_price'];

        $request->update([
            'item_code' => $payload['item_code'],
            'item_name' => $payload['item_name'],
            'quantity' => $payload['quantity'],
            'unit' => $payload['unit'],
            'unit_price' => $payload['unit_price'],
            'total_amount' => $total,
            'reason' => $payload['reason'],
            'status' => 'pending_finance',
            'finance_approval' => null,
            'management_approval' => null,
            'ordered_by' => null,
            'ordered_at' => null,
            'ordered_notes' => null,
        ]);

        $this->log($actor, 'Procurement Revised', $request->id, 'Request procurement direvisi dan dikirim ulang ke finance.', 'warning');

        return $request->fresh();
    }

    public function financeApprove(ProcurementRequest $request, User $actor, ?string $notes = null): ProcurementRequest
    {
        abort_unless($request->status === 'pending_finance', 422, 'Request procurement ini tidak sedang menunggu review finance.');

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
            'rejection_notes' => null,
            'last_rejected_by' => null,
            'last_rejected_at' => null,
        ]);

        $this->log($actor, 'Finance Approved Procurement', $request->id, $notes ?? 'Approved by finance.', 'success');

        return $request->fresh();
    }

    public function financeReject(ProcurementRequest $request, User $actor, string $notes): ProcurementRequest
    {
        abort_unless($request->status === 'pending_finance', 422, 'Request procurement ini tidak sedang menunggu review finance.');

        $request->update([
            'status' => 'rejected',
            'rejection_notes' => $notes,
            'last_rejected_by' => $actor->name,
            'last_rejected_at' => Carbon::now(),
        ]);

        $this->log($actor, 'Finance Rejected Procurement', $request->id, $notes, 'warning');

        return $request->fresh();
    }

    public function managementApprove(ProcurementRequest $request, User $actor, ?string $notes = null): ProcurementRequest
    {
        abort_unless($request->status === 'pending_management', 422, 'Request procurement ini tidak sedang menunggu approval atasan.');

        $request->update([
            'status' => 'approved',
            'management_approval' => [
                'approved' => true,
                'by' => $actor->name,
                'at' => Carbon::now()->format('Y-m-d H:i:s'),
                'notes' => $notes,
            ],
            'rejection_notes' => null,
            'last_rejected_by' => null,
            'last_rejected_at' => null,
        ]);

        $this->log($actor, 'Management Approved Procurement', $request->id, $notes ?? 'Approved by management.', 'success');

        return $request->fresh();
    }

    public function managementReject(ProcurementRequest $request, User $actor, string $notes): ProcurementRequest
    {
        abort_unless($request->status === 'pending_management', 422, 'Request procurement ini tidak sedang menunggu approval atasan.');

        $request->update([
            'status' => 'rejected',
            'rejection_notes' => $notes,
            'last_rejected_by' => $actor->name,
            'last_rejected_at' => Carbon::now(),
        ]);

        $this->log($actor, 'Management Rejected Procurement', $request->id, $notes, 'warning');

        return $request->fresh();
    }

    public function markProcurementOrdered(ProcurementRequest $request, User $actor, ?string $notes = null): ProcurementRequest
    {
        abort_unless($request->status === 'approved', 422, 'Hanya request procurement yang sudah approved yang bisa ditandai sedang dibeli.');

        $request->update([
            'status' => 'ordered',
            'ordered_by' => $actor->name,
            'ordered_at' => Carbon::now(),
            'ordered_notes' => $notes,
        ]);

        $this->log($actor, 'Procurement Ordered', $request->id, $notes ?? 'Warehouse mulai melakukan pembelian barang.', 'info');

        return $request->fresh();
    }

    public function receiveProcurement(ProcurementRequest $request, User $actor): ProcurementRequest
    {
        abort_unless($request->status === 'ordered', 422, 'Barang hanya bisa diterima setelah procurement ditandai sedang dibeli.');

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

    private function createInstallationMaterialRequest(
        ServiceRegistration $registration,
        WorkOrder $workOrder,
        User $actor,
        array $items
    ): InstallationMaterialRequest {
        return InstallationMaterialRequest::query()->create([
            'id' => $this->nextCode('IMR', InstallationMaterialRequest::query()->count() + 1),
            'service_registration_id' => $registration->id,
            'work_order_id' => $workOrder->id,
            'ticket_id' => null,
            'customer_name' => $registration->name,
            'requested_by' => $actor->name,
            'request_purpose' => 'installation',
            'status' => 'menunggu_persetujuan_gudang',
            'items' => $items,
            'approval_notes' => 'Material sedang disiapkan oleh gudang.',
        ]);
    }

    private function createMaintenanceMaterialRequest(
        TroubleTicket $ticket,
        WorkOrder $workOrder,
        User $actor,
        array $items
    ): InstallationMaterialRequest {
        return InstallationMaterialRequest::query()->updateOrCreate(
            ['work_order_id' => $workOrder->id],
            [
                'id' => InstallationMaterialRequest::query()->where('work_order_id', $workOrder->id)->value('id')
                    ?: $this->nextCode('IMR', InstallationMaterialRequest::query()->count() + 1),
                'service_registration_id' => null,
                'work_order_id' => $workOrder->id,
                'ticket_id' => $ticket->id,
                'customer_name' => $ticket->customer_name,
                'requested_by' => $actor->name,
                'request_purpose' => 'maintenance_replacement',
                'status' => 'menunggu_persetujuan_gudang',
                'items' => $items,
            ],
        );
    }

    private function defaultInstallationMaterials(): array
    {
        return [
            ['itemName' => 'ONU', 'quantity' => 1, 'unit' => 'Unit'],
            ['itemName' => 'Patch Cord', 'quantity' => 1, 'unit' => 'Pcs'],
            ['itemName' => 'Kabel Dropcore', 'quantity' => 100, 'unit' => 'Meter'],
            ['itemName' => 'Adaptor', 'quantity' => 1, 'unit' => 'Pcs'],
        ];
    }

    private function defaultReplacementMaterials(): array
    {
        return [
            ['itemName' => 'ONU', 'quantity' => 1, 'unit' => 'Unit'],
            ['itemName' => 'Adaptor', 'quantity' => 1, 'unit' => 'Pcs'],
        ];
    }

    private function normalizeWarehouseItems(array $items): array
    {
        return collect($items)
            ->map(function (array $item): array {
                return [
                    'itemName' => $item['itemName'] ?? $item['item_name'] ?? 'Perangkat',
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'unit' => $item['unit'] ?? 'Unit',
                    'returnCategory' => $item['returnCategory'] ?? $item['return_category'] ?? 'unused_replacement',
                    'serialNumbers' => is_array($item['serialNumbers'] ?? null) ? array_values($item['serialNumbers']) : [],
                ];
            })
            ->values()
            ->all();
    }

    private function parseOnuIdentityCandidate(WorkOrder $workOrder, array $report): array
    {
        $fallbackSeed = strtoupper(str_replace('-', '', $workOrder->id));
        $normalizedMac = substr($fallbackSeed.'A1B2C3D4E5F6', 0, 12);

        return [
            'ponSn' => $report['pon_sn'] ?? ('PON-'.$fallbackSeed),
            'serialNumber' => $report['onu_serial_number'] ?? $report['new_ont_serial_number'] ?? ('ONU-'.$fallbackSeed),
            'macAddress' => $report['mac_address'] ?? implode(':', str_split($normalizedMac, 2)),
            'source' => ($report['pon_sn'] ?? $report['onu_serial_number'] ?? $report['mac_address']) ? 'manual_confirmed' : 'semi_auto_candidate',
        ];
    }

    private function createOrRefreshWarehouseReturnRequest(
        WorkOrder $workOrder,
        User $actor,
        array $returnItems,
        bool $replacementApplied,
        string $returnType = 'replacement'
    ): WarehouseReturnRequest {
        $normalizedItems = ! empty($returnItems)
            ? $returnItems
            : $this->normalizeWarehouseItems($this->inferWarehouseReturnItemsFromWorkOrder($workOrder, $replacementApplied, $returnType));

        return WarehouseReturnRequest::query()->updateOrCreate(
            ['work_order_id' => $workOrder->id],
            [
                'id' => WarehouseReturnRequest::query()->where('work_order_id', $workOrder->id)->value('id')
                    ?: $this->nextCode('RTR', WarehouseReturnRequest::query()->count() + 1),
                'ticket_id' => $workOrder->ticket_id,
                'customer_id' => $workOrder->customer_id,
                'customer_name' => $workOrder->customer_name,
                'submitted_by' => $actor->name,
                'return_type' => $returnType,
                'status' => 'menunggu_qc_gudang',
                'items' => $normalizedItems,
            ],
        );
    }

    private function inferWarehouseReturnItemsFromWorkOrder(WorkOrder $workOrder, bool $replacementApplied, string $returnType = 'replacement'): array
    {
        if ($returnType === 'uninstallation') {
            return $this->inferUninstallationReturnItems($workOrder);
        }

        $requiredMaterials = collect($workOrder->required_materials ?? []);

        if ($replacementApplied) {
            return [
                [
                    'item_name' => 'ONU Lama / Error',
                    'quantity' => 1,
                    'unit' => 'Unit',
                    'return_category' => 'old_defective',
                ],
                ...$requiredMaterials
                    ->take(1)
                    ->map(fn (array $item) => [
                        'item_name' => $item['itemName'] ?? 'Perangkat Pengganti',
                        'quantity' => 0,
                        'unit' => $item['unit'] ?? 'Unit',
                        'return_category' => 'installed_replacement',
                    ])
                    ->all(),
            ];
        }

        return $requiredMaterials
            ->map(fn (array $item) => [
                'item_name' => $item['itemName'] ?? 'Perangkat Pengganti',
                'quantity' => (int) ($item['quantity'] ?? 1),
                'unit' => $item['unit'] ?? 'Unit',
                'return_category' => 'unused_replacement',
            ])
            ->all();
    }

    private function createUninstallationOperationalFlow(Customer $customer, User $actor, ?string $notes = null): void
    {
        $existingTicket = TroubleTicket::query()
            ->where('customer_id', $customer->id)
            ->where('category', 'uninstallation')
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->latest()
            ->first();

        $ticket = $existingTicket ?: TroubleTicket::query()->create([
            'id' => $this->nextCode('TKT', TroubleTicket::query()->count() + 880),
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_address' => $customer->address,
            'region' => $customer->region,
            'odp_id' => $customer->odp_id,
            'category' => 'uninstallation',
            'title' => 'Pencabutan Alat / Uninstall Pelanggan',
            'description' => $notes ?: 'Permintaan pencabutan alat pelanggan dibuat dari perubahan status layanan.',
            'priority' => 'medium',
            'status' => 'assigned_to_lead',
            'created_by' => $actor->name.' ('.$actor->role_title.')',
            'can_be_resolved_remotely' => false,
            'replacement_context' => [
                'returnType' => 'uninstallation',
                'warehouseReturnStatus' => null,
                'holdTicketUntilWarehouseReturn' => true,
            ],
        ]);

        $existingWorkOrder = WorkOrder::query()
            ->where('customer_id', $customer->id)
            ->where('type', 'uninstallation')
            ->whereNotIn('status', ['closed', 'completed'])
            ->latest()
            ->first();

        if (! $existingWorkOrder) {
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
                'ticket_id' => $ticket->id,
                'status' => 'pending_lead_assignment',
                'scheduled_date' => Carbon::now()->addDay()->format('Y-m-d 10:00'),
                'package_plan' => $customer->package_plan,
                'required_materials' => $this->buildUninstallationDevicePrefill($customer),
            ]);
        }
    }

    private function buildUninstallationDevicePrefill(Customer $customer): array
    {
        $items = [
            [
                'itemName' => trim(($customer->ont_brand ?: 'ONU').' '.($customer->ont_model ?: 'Pelanggan')),
                'quantity' => 1,
                'unit' => 'Unit',
            ],
            [
                'itemName' => 'Adaptor',
                'quantity' => 1,
                'unit' => 'Pcs',
            ],
        ];

        $metaItems = collect(data_get($customer->meta, 'companyOwnedDevices', []))
            ->filter(fn ($item) => is_array($item) && ! empty($item['itemName']))
            ->map(fn (array $item) => [
                'itemName' => $item['itemName'],
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'unit' => $item['unit'] ?? 'Unit',
            ])
            ->all();

        return collect([...$items, ...$metaItems])
            ->unique(fn (array $item) => strtolower($item['itemName']).'|'.$item['unit'])
            ->values()
            ->all();
    }

    private function inferUninstallationReturnItems(WorkOrder $workOrder): array
    {
        $customer = $workOrder->customer_id ? Customer::query()->find($workOrder->customer_id) : null;

        return collect($workOrder->required_materials ?? [])
            ->map(function (array $item) use ($customer): array {
                $serialNumbers = [];
                $itemName = (string) ($item['itemName'] ?? 'Perangkat Pelanggan');

                if ($customer?->ont_serial_number && str_contains(strtolower($itemName), 'onu')) {
                    $serialNumbers[] = $customer->ont_serial_number;
                }

                return [
                    'item_name' => $itemName,
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'unit' => $item['unit'] ?? 'Unit',
                    'return_category' => 'returned_good',
                    'serialNumbers' => $serialNumbers,
                ];
            })
            ->values()
            ->all();
    }

    private function normalizeReimbursementItems(array $items): array
    {
        return collect($items)
            ->map(function (array $item): array {
                $quantity = (int) ($item['quantity'] ?? 0);
                $unitAmount = (int) ($item['unitAmount'] ?? 0);
                $itemName = trim((string) ($item['itemName'] ?? ''));
                $unit = trim((string) ($item['unit'] ?? ''));

                return [
                    'item_name' => $itemName,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'unit_amount' => $unitAmount,
                    'subtotal' => max($quantity, 0) * max($unitAmount, 0),
                    'notes' => $item['notes'] ?? null,
                ];
            })
            ->filter(fn (array $item) => $item['item_name'] !== '' && $item['quantity'] > 0 && $item['unit'] !== '' && $item['unit_amount'] > 0)
            ->values()
            ->all();
    }
}
