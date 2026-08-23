<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_fetch_profile(): void
    {
        $this->seed();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'superadmin@isp-ops.net',
            'password' => 'password',
        ])->assertOk();

        $token = $login->json('token');

        $this->assertNotNull(PersonalAccessToken::query()->where('tokenable_id', 'USR-01')->first());

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', 'USR-01');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/navigation')
            ->assertOk()
            ->assertJsonPath('data.role', 'superadmin')
            ->assertJsonPath('data.allowedModuleKeys.0', 'dashboard')
            ->assertJsonMissing(['key' => 'admin_users']);
    }

    public function test_user_can_change_password_and_old_password_becomes_invalid(): void
    {
        $this->seed();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'superadmin@isp-ops.net',
            'password' => 'password',
        ])->assertOk();

        $token = $login->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@isp-ops.net',
            'password' => 'password',
        ])->assertStatus(422);

        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@isp-ops.net',
            'password' => 'new-password-123',
        ])->assertOk();
    }

    public function test_user_can_request_and_complete_password_reset(): void
    {
        Notification::fake();
        $this->seed();

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'superadmin@isp-ops.net',
        ])->assertOk();

        $user = User::query()->where('email', 'superadmin@isp-ops.net')->firstOrFail();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'reset-password-123',
            'password_confirmation' => 'reset-password-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('reset-password-123', $user->fresh()->password));
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'reset-password-123',
        ])->assertOk();
    }

    public function test_login_is_rate_limited_after_multiple_failed_attempts(): void
    {
        $this->seed();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/auth/login', [
                'email' => 'superadmin@isp-ops.net',
                'password' => 'invalid-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@isp-ops.net',
            'password' => 'invalid-password',
        ])->assertStatus(429);
    }
}
