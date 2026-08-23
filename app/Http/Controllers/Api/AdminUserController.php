<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminResetUserPasswordRequest;
use App\Http\Requests\AdminStoreUserRequest;
use App\Http\Requests\AdminUpdateUserRequest;
use App\Http\Requests\AdminUpdateUserStatusRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminUserController extends Controller
{
    public function index()
    {
        return AdminUserResource::collection(User::query()->orderBy('name')->get());
    }

    public function store(AdminStoreUserRequest $request)
    {
        $nextNumber = User::query()->count() + 1;
        $role = Role::query()->findOrFail($request->string('role')->toString());

        $user = User::query()->create([
            'id' => 'USR-'.str_pad((string) $nextNumber, 2, '0', STR_PAD_LEFT),
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'role' => $role->key,
            'role_title' => $role->label,
            'division' => $role->division,
            'phone' => $request->input('phone'),
            'password' => $request->string('password')->toString(),
            'is_active' => $request->boolean('is_active', true),
            'is_online' => false,
        ]);

        $this->logAdminEvent($request, 'Create User', $user->email, 'Akun baru dibuat oleh superadmin.');

        return AdminUserResource::make($user)->response()->setStatusCode(201);
    }

    public function update(AdminUpdateUserRequest $request, string $user)
    {
        $targetUser = User::query()->findOrFail($user);
        $role = Role::query()->findOrFail($request->string('role')->toString());

        $targetUser->update([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'role' => $role->key,
            'role_title' => $role->label,
            'division' => $role->division,
            'phone' => $request->input('phone'),
            'is_active' => $request->boolean('is_active'),
        ]);

        if (! $targetUser->is_active) {
            $targetUser->tokens()->delete();
            $targetUser->update(['is_online' => false]);
        }

        $this->logAdminEvent($request, 'Update User', $targetUser->email, 'Profil akun diperbarui oleh superadmin.');

        return AdminUserResource::make($targetUser->fresh());
    }

    public function resetPassword(AdminResetUserPasswordRequest $request, string $user)
    {
        $targetUser = User::query()->findOrFail($user);

        $targetUser->forceFill([
            'password' => $request->string('password')->toString(),
        ])->save();

        $targetUser->tokens()->delete();
        $targetUser->update(['is_online' => false]);

        $this->logAdminEvent($request, 'Reset User Password', $targetUser->email, 'Password akun direset oleh superadmin.');

        return response()->json(['message' => 'Password akun berhasil direset.']);
    }

    public function updateStatus(AdminUpdateUserStatusRequest $request, string $user)
    {
        $targetUser = User::query()->findOrFail($user);

        $targetUser->update([
            'is_active' => $request->boolean('is_active'),
            'is_online' => $request->boolean('is_active') ? $targetUser->is_online : false,
        ]);

        if (! $targetUser->is_active) {
            $targetUser->tokens()->delete();
        }

        $this->logAdminEvent(
            $request,
            $targetUser->is_active ? 'Activate User' : 'Deactivate User',
            $targetUser->email,
            $targetUser->is_active ? 'Akun diaktifkan oleh superadmin.' : 'Akun dinonaktifkan oleh superadmin.'
        );

        return AdminUserResource::make($targetUser->fresh());
    }

    private function logAdminEvent(Request $request, string $action, string $target, string $details): void
    {
        $actor = $request->user();

        AuditLog::query()->create([
            'id' => 'LOG-'.Str::upper(Str::random(8)),
            'timestamp' => now(),
            'actor_name' => $actor->name,
            'actor_role' => $actor->role,
            'action' => $action,
            'target' => $target,
            'details' => $details,
            'type' => 'info',
        ]);
    }
}
