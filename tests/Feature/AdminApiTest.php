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
            ->assertJsonPath('data.totalUsers', 9);

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
            ->assertJsonCount(4, 'data');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/roles')
            ->assertOk()
            ->assertJsonCount(9, 'data');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/roles', [
                'role' => 'customer_success',
                'role_title' => 'Customer Success Specialist',
                'division' => 'Customer Experience',
                'description' => 'Monitoring onboarding dan follow up pelanggan aktif.',
                'sort_order' => 10,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'customer_success');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/admin/roles/helpdesk', [
                'role_title' => 'Helpdesk Internal Ops',
                'division' => 'Customer Service Internal',
                'description' => 'Intake tiket dan koordinasi aduan pelanggan.',
                'sort_order' => 5,
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.roleTitle', 'Helpdesk Internal Ops');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/admin/roles/customer_success/status', [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.isActive', false);

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
            ->getJson('/api/admin/modules')
            ->assertOk()
            ->assertJsonPath('data.modules.0.key', 'dashboard');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/modules', [
                'key' => 'customer_portal',
                'label' => 'Customer Portal',
                'description' => 'Modul portal pelanggan untuk fase berikutnya.',
                'navigation_head_key' => 'operasional',
                'order' => 11,
                'route_target' => '/app/customer-portal',
                'quick_action' => null,
                'view_formats' => ['table'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'customer_portal');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/modules', [
                'key' => 'admin_module_roles',
                'label' => 'Modul To Role',
                'description' => 'Mapping modul untuk role.',
                'navigation_head_key' => 'administrasi',
                'order' => 8,
                'route_target' => '/app/admin/module-roles',
                'quick_action' => null,
                'view_formats' => ['table'],
                'is_active' => true,
            ])
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/modules', [
                'key' => 'broken_route_module',
                'label' => 'Broken Route Module',
                'description' => 'Harus ditolak karena route bukan path internal.',
                'navigation_head_key' => 'operasional',
                'order' => 12,
                'route_target' => 'helpdesk',
                'quick_action' => null,
                'view_formats' => ['table'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ])
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/admin/modules/helpdesk', [
                'label' => 'Helpdesk Internal',
                'description' => 'Aduan dan intake tiket internal.',
                'navigation_head_key' => 'operasional',
                'order' => 2,
                'route_target' => '/app/helpdesk',
                'quick_action' => 'new_ticket',
                'view_formats' => ['table', 'grid'],
                'is_active' => true,
                'show_in_navbar' => true,
                'admin_only_dashboard' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.label', 'Helpdesk Internal');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/module-role-mappings')
            ->assertOk()
            ->assertJsonPath('data.roles.0.role', 'superadmin');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/admin/module-role-mappings/helpdesk', [
                'mappings' => [
                    ['module_key' => 'helpdesk', 'is_visible' => true, 'order_override' => 1],
                    ['module_key' => 'kanban', 'is_visible' => true, 'order_override' => 2],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/navigation-config')
            ->assertOk()
            ->assertJsonPath('data.heads.0.key', 'dashboards');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/admin/navigation-config', [
                'heads' => [
                    ['key' => 'dashboards', 'label' => 'Dashboards', 'order' => 1, 'is_active' => true],
                    ['key' => 'operasional', 'label' => 'Operasional', 'order' => 2, 'is_active' => true],
                    ['key' => 'koordinasi', 'label' => 'Koordinasi', 'order' => 3, 'is_active' => true],
                    ['key' => 'infrastruktur', 'label' => 'Infrastruktur', 'order' => 4, 'is_active' => true],
                    ['key' => 'administrasi', 'label' => 'Administrasi Sistem', 'order' => 5, 'is_active' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.heads.4.key', 'administrasi');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/mappings')
            ->assertOk()
            ->assertJsonPath('data.networkSummary.totalOdps', 1);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/sessions')
            ->assertOk()
            ->assertJsonCount(10, 'data');

        $this->assertDatabaseHas('audit_logs', ['action' => 'Create User', 'target' => 'admin.data@isp-ops.net']);
        $this->assertDatabaseHas('roles', ['key' => 'customer_success', 'is_active' => false]);
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
