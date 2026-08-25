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

    public function test_rejected_procurement_can_be_revised_then_ordered_and_received(): void
    {
        $this->seed();

        $this->loginAs('gudang.inventory@isp-ops.net');
        $created = $this->postJson('/api/procurements', [
            'item_code' => 'ONU-01',
            'item_name' => 'ONU GPON 1 Port',
            'quantity' => 25,
            'unit' => 'Unit',
            'unit_price' => 300000,
            'reason' => 'Buffer stock awal.',
        ])->assertCreated();

        $procurementId = $created->json('data.id');

        $this->loginAs('finance.billing@isp-ops.net');
        $this->postJson('/api/procurements/'.$procurementId.'/finance-reject', [
            'notes' => 'Mohon revisi qty agar sesuai kebutuhan bulan ini.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejectionNotes', 'Mohon revisi qty agar sesuai kebutuhan bulan ini.');

        $this->loginAs('gudang.inventory@isp-ops.net');
        $this->putJson('/api/procurements/'.$procurementId, [
            'item_code' => 'ONU-01',
            'item_name' => 'ONU GPON 1 Port',
            'quantity' => 15,
            'unit' => 'Unit',
            'unit_price' => 300000,
            'reason' => 'Revisi kebutuhan buffer stock bulan berjalan.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'pending_finance');

        $this->loginAs('finance.billing@isp-ops.net');
        $this->postJson('/api/procurements/'.$procurementId.'/finance-approve', [
            'notes' => 'Budget tersedia dan bisa diproses.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->loginAs('gudang.inventory@isp-ops.net');
        $this->postJson('/api/procurements/'.$procurementId.'/mark-ordered', [
            'notes' => 'PO internal dikirim ke vendor utama.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'ordered');

        $this->postJson('/api/procurements/'.$procurementId.'/receive')
            ->assertOk()
            ->assertJsonPath('data.status', 'received');
    }

    private function loginAs(string $email): void
    {
        Sanctum::actingAs(User::query()->where('email', $email)->firstOrFail());
    }
}
