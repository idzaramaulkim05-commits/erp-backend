<?php

namespace Tests\Feature;

use App\Models\AdminMasterDataGroup;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_manage_admin_endpoints(): void
    {
        $this->seed();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'superadmin@isp-ops.net',
            'password' => 'password',
        ])->assertOk();

        $token = $login->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.totalUsers', 8);

        $createdUser = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/users', [
                'name' => 'Admin Data Baru',
                'email' => 'admin.data@isp-ops.net',
                'role' => 'inventory',
                'role_title' => 'Admin Data Inventaris',
                'division' => 'Warehouse & Asset Logistics',
                'phone' => '081255500000',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'is_active' => true,
            ])
            ->assertCreated();

        $userId = $createdUser->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/admin/users/{$userId}", [
                'name' => 'Admin Data Update',
                'email' => 'admin.data@isp-ops.net',
                'role' => 'inventory',
                'role_title' => 'Admin Data Inventaris',
                'division' => 'Warehouse & Asset Logistics',
                'phone' => '081255500111',
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Admin Data Update');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/admin/users/{$userId}/reset-password", [
                'password' => 'password456',
                'password_confirmation' => 'password456',
            ])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/admin/users/{$userId}/status", [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.isActive', false);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/master-data')
            ->assertOk()
            ->assertJsonCount(5, 'data');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/admin/master-data/regions', [
                'items' => [
                    ['name' => 'Sidoarjo Kota', 'clusterCode' => 'SDA'],
                    ['name' => 'Waru Baru', 'clusterCode' => 'WAR2'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.key', 'regions');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/mappings')
            ->assertOk()
            ->assertJsonPath('data.networkSummary.totalOdps', 1);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/sessions')
            ->assertOk()
            ->assertJsonCount(9, 'data');

        $this->assertDatabaseHas('audit_logs', ['action' => 'Create User', 'target' => 'admin.data@isp-ops.net']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Update Master Data', 'target' => 'regions']);
    }

    public function test_non_superadmin_cannot_access_admin_endpoints(): void
    {
        $this->seed();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'hendra.direksi@isp-ops.net',
            'password' => 'password',
        ])->assertOk();

        $token = $login->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/overview')
            ->assertStatus(403);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->seed();

        User::query()->where('email', 'helpdesk@isp-ops.net')->update(['is_active' => false]);

        $this->postJson('/api/auth/login', [
            'email' => 'helpdesk@isp-ops.net',
            'password' => 'password',
        ])->assertStatus(403);
    }
}
