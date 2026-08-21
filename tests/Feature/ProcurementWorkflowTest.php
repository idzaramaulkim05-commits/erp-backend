<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProcurementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_small_procurement_only_needs_finance_and_large_procurement_needs_management(): void
    {
        $this->seed();
        $this->loginAs('gudang.inventory@isp-ops.net');
        $small = $this->postJson('/api/procurements', [
                'item_code' => 'PATCH-01',
                'item_name' => 'Patch Cord SC-UPC 3M',
                'quantity' => 5,
                'unit' => 'Pcs',
                'unit_price' => 25000,
                'reason' => 'Restock cepat.',
            ])->assertCreated();

        $this->loginAs('finance.billing@isp-ops.net');
        $this->postJson('/api/procurements/'.$small->json('data.id').'/finance-approve', ['notes' => 'Disetujui finance.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->loginAs('gudang.inventory@isp-ops.net');
        $large = $this->postJson('/api/procurements', [
                'item_code' => 'OLT-01',
                'item_name' => 'OLT Expansion Card',
                'quantity' => 1,
                'unit' => 'Unit',
                'unit_price' => 12000000,
                'reason' => 'Perlu upgrade kapasitas.',
            ])->assertCreated();

        $this->loginAs('finance.billing@isp-ops.net');
        $this->postJson('/api/procurements/'.$large->json('data.id').'/finance-approve', ['notes' => 'Naikkan ke direksi.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_management');
    }

    private function loginAs(string $email): void
    {
        Sanctum::actingAs(User::query()->where('email', $email)->firstOrFail());
    }
}
