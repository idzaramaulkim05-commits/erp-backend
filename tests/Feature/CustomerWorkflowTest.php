<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\NetworkOdpPort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_customer_reserves_odp_port_and_creates_installation_work_order(): void
    {
        $this->seed();
        $this->loginAs('helpdesk@isp-ops.net');

        $response = $this->postJson('/api/customers', [
                'name' => 'Pelanggan Baru',
                'nik' => '3515082405890001',
                'phone' => '081298765111',
                'address' => 'Jl. Test No. 1',
                'region' => 'Sidoarjo Kota',
                'package_plan' => 'Home Fiber 50 Mbps',
                'monthly_fee' => 250000,
                'odp_id' => 'ODP-SDA-01/01',
                'initial_deposit_paid' => true,
            ])->assertOk();

        $customerId = $response->json('data.id');

        $this->assertDatabaseHas('customers', ['id' => $customerId]);
        $this->assertDatabaseHas('work_orders', ['customer_id' => $customerId, 'type' => 'installation']);
        $this->assertGreaterThan(0, NetworkOdpPort::query()->where('customer_id', $customerId)->count());
    }

    private function loginAs(string $email): void
    {
        Sanctum::actingAs(User::query()->where('email', $email)->firstOrFail());
    }
}
