<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_can_flow_from_creation_to_remote_resolution(): void
    {
        $this->seed();
        $this->loginAs('helpdesk@isp-ops.net');

        $created = $this->postJson('/api/tickets', [
                'customer_id' => 'CUST-1042',
                'title' => 'WiFi issue',
                'description' => 'Perlu reset SSID.',
                'category' => 'wifi_issue',
                'priority' => 'medium',
            ])->assertCreated();

        $ticketId = $created->json('data.id');
        $this->loginAs('noc.lead@isp-ops.net');

        $this->postJson("/api/tickets/{$ticketId}/remote-resolve", ['notes' => 'Reset profile PPPoE dan update SSID.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_ticket_can_be_escalated_then_closed_after_lead_and_noc_actions(): void
    {
        $this->seed();
        $this->loginAs('helpdesk@isp-ops.net');
        $created = $this->postJson('/api/tickets', [
                'customer_id' => 'CUST-1042',
                'title' => 'LOS merah',
                'description' => 'Lampu LOS merah berkedip.',
                'category' => 'los_red_light',
                'priority' => 'high',
            ])->assertCreated();

        $ticketId = $created->json('data.id');
        $this->loginAs('noc.lead@isp-ops.net');

        $this->postJson("/api/tickets/{$ticketId}/escalate", ['notes' => 'Perlu pengecekan fisik drop cable.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned_to_lead');

        $this->loginAs('lead.tech@isp-ops.net');
        $this->postJson("/api/tickets/{$ticketId}/lead-approve", [
                'sop_checklist' => [
                    'cablesNeatlyClamped' => true,
                    'protectionSleeveInstalled' => true,
                    'customerAreaCleaned' => true,
                    'speedtestVerified' => true,
                ],
            ])->assertOk();

        $this->loginAs('noc.lead@isp-ops.net');
        $this->postJson("/api/tickets/{$ticketId}/noc-close", [
                'optical_dbm_reading' => -20.1,
                'pppoe_session_active' => true,
                'rx_power_threshold_passed' => true,
                'notes' => 'Koneksi normal kembali.',
            ])->assertOk()
            ->assertJsonPath('data.status', 'closed');
    }

    private function loginAs(string $email): void
    {
        Sanctum::actingAs(User::query()->where('email', $email)->firstOrFail());
    }
}
