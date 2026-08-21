<?php

namespace Tests\Feature;

use App\Models\ServiceRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceRegistrationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_registration_flows_from_sales_to_noc_final_verification(): void
    {
        $this->seed();

        $this->loginAs('sales@isp-ops.net');
        $created = $this->postJson('/api/service-registrations', [
            'name' => 'Calon Pelanggan Sales',
            'nik' => '3515082405891001',
            'phone' => '081298761234',
            'address' => 'Jl. Registrasi Baru No. 7',
            'region' => 'Sidoarjo Kota',
            'package_plan' => 'Home Fiber 50 Mbps',
            'monthly_fee' => 250000,
            'odp_id' => 'ODP-SDA-01/01',
        ])->assertOk();

        $registrationId = $created->json('data.id');

        $this->assertDatabaseHas('service_registrations', [
            'id' => $registrationId,
            'status' => 'draft',
            'finance_status' => 'pending',
            'noc_status' => 'pending',
        ]);
        $this->assertDatabaseMissing('customers', ['name' => 'Calon Pelanggan Sales']);

        $this->postJson("/api/service-registrations/{$registrationId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_finance');

        $this->loginAs('finance.billing@isp-ops.net');
        $this->postJson("/api/service-registrations/{$registrationId}/finance-approve", [
            'notes' => 'Deposit dan biaya instalasi valid.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'pending_noc')
            ->assertJsonPath('data.financeStatus', 'approved');

        $this->loginAs('noc.lead@isp-ops.net');
        $this->postJson("/api/service-registrations/{$registrationId}/generate-pppoe")
            ->assertOk()
            ->assertJsonPath('data.pppoeUsername', fn ($value) => is_string($value) && $value !== '')
            ->assertJsonPath('data.pppoePassword', fn ($value) => is_string($value) && strlen($value) === 10);

        $this->postJson("/api/service-registrations/{$registrationId}/noc-approve", [
            'notes' => 'ODP dan port valid untuk instalasi.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'noc_approved')
            ->assertJsonPath('data.nocStatus', 'approved');

        $workOrderCreated = $this->postJson("/api/service-registrations/{$registrationId}/create-work-order")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_for_dispatch');

        $workOrderId = $workOrderCreated->json('data.workOrderId');
        $customerId = $workOrderCreated->json('data.customerId');

        $this->assertDatabaseHas('customers', ['id' => $customerId, 'status' => 'active']);
        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrderId,
            'customer_id' => $customerId,
            'service_registration_id' => $registrationId,
            'status' => 'pending_lead_assignment',
        ]);

        $this->loginAs('lead.tech@isp-ops.net');
        $this->postJson("/api/work-orders/{$workOrderId}/lead-assign", [
            'tech_id' => 'USR-06',
        ])->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.assignedTechId', 'USR-06');

        $this->loginAs('teknisi.bambang@isp-ops.net');
        $this->postJson("/api/work-orders/{$workOrderId}/submit-installation-report", [
            'action_taken' => 'Instalasi ONT selesai dan lampu PON stabil.',
            'final_optical_power_dbm' => -20.3,
            'patch_cord_replaced' => true,
            'drop_cable_length_meters' => 120,
            'signature' => 'Bambang Irawan',
        ])->assertOk()
            ->assertJsonPath('data.status', 'waiting_noc_activation');

        $this->assertDatabaseHas('service_registrations', [
            'id' => $registrationId,
            'status' => 'field_submitted',
        ]);

        $this->loginAs('noc.lead@isp-ops.net');
        $this->postJson("/api/work-orders/{$workOrderId}/noc-final-verify", [
            'optical_dbm_reading' => -20.1,
            'pppoe_session_active' => true,
            'rx_power_threshold_passed' => true,
            'notes' => 'Aktivasi berhasil dan sesi PPPoE normal.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.nocActivated', true);

        $this->assertDatabaseHas('service_registrations', [
            'id' => $registrationId,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrderId,
            'status' => 'completed',
        ]);
    }

    public function test_sales_cannot_directly_approve_finance_stage(): void
    {
        $this->seed();

        ServiceRegistration::query()->create([
            'id' => 'SR-999',
            'name' => 'Unauthorized Sales',
            'nik' => '3515082405891002',
            'phone' => '081298761235',
            'address' => 'Jl. Audit Role No. 1',
            'region' => 'Sidoarjo Kota',
            'package_plan' => 'Home Fiber 30 Mbps',
            'monthly_fee' => 200000,
            'odp_id' => 'ODP-SDA-01/01',
            'status' => 'pending_finance',
            'finance_status' => 'pending',
            'noc_status' => 'pending',
            'requested_by_id' => 'USR-03',
        ]);

        $this->loginAs('sales@isp-ops.net');
        $this->postJson('/api/service-registrations/SR-999/finance-approve', [
            'notes' => 'Tidak boleh lolos.',
        ])->assertForbidden();
    }

    private function loginAs(string $email): void
    {
        Sanctum::actingAs(User::query()->where('email', $email)->firstOrFail());
    }
}
