<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleMetaResource;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AdminRoleMetaController extends Controller
{
    public function index()
    {
        return RoleMetaResource::collection($this->buildRoleMetaCollection());
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'role' => ['required', 'string', 'max:64', 'alpha_dash', 'unique:roles,key'],
            'role_title' => ['required', 'string', 'max:255'],
            'division' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $role = Role::query()->create([
            'key' => $payload['role'],
            'label' => $payload['role_title'],
            'division' => $payload['division'],
            'description' => $payload['description'] ?? null,
            'sort_order' => $payload['sort_order'] ?? ((int) Role::query()->max('sort_order')) + 1,
            'is_active' => $payload['is_active'] ?? true,
        ]);

        $this->logAudit($request, 'Create Role', $role->key, 'Role system baru dibuat oleh superadmin.');

        return RoleMetaResource::make($this->mapRole($role))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $role)
    {
        $payload = $request->validate([
            'role_title' => ['required', 'string', 'max:255'],
            'division' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $roleModel = Role::query()->findOrFail($role);
        $roleModel->update([
            'label' => $payload['role_title'],
            'division' => $payload['division'],
            'description' => $payload['description'] ?? null,
            'sort_order' => $payload['sort_order'] ?? $roleModel->sort_order,
            ...($request->has('is_active') ? ['is_active' => $request->boolean('is_active')] : []),
        ]);

        User::query()
            ->where('role', $role)
            ->update([
                'role_title' => $payload['role_title'],
                'division' => $payload['division'],
                ...($request->has('is_active') ? ['is_active' => $request->boolean('is_active')] : []),
            ]);

        if ($request->has('is_active') && ! $request->boolean('is_active')) {
            User::query()->where('role', $role)->update(['is_online' => false]);
            User::query()->where('role', $role)->each(function (User $user) {
                $user->tokens()->delete();
            });
        }

        $this->logAudit($request, 'Update Role Meta', $role, 'Metadata role diperbarui oleh superadmin.');

        return RoleMetaResource::make($this->mapRole($roleModel->fresh()));
    }

    public function updateStatus(Request $request, string $role)
    {
        $payload = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $roleModel = Role::query()->findOrFail($role);
        $roleModel->update(['is_active' => $payload['is_active']]);

        User::query()->where('role', $role)->update([
            'is_active' => $payload['is_active'],
            'is_online' => false,
        ]);

        if (! $payload['is_active']) {
            User::query()->where('role', $role)->each(function (User $user) {
                $user->tokens()->delete();
            });
        }

        $this->logAudit(
            $request,
            $payload['is_active'] ? 'Activate Role' : 'Deactivate Role',
            $role,
            $payload['is_active'] ? 'Role diaktifkan oleh superadmin.' : 'Role dinonaktifkan oleh superadmin.'
        );

        return RoleMetaResource::make($this->mapRole($roleModel->fresh()));
    }

    private function buildRoleMetaCollection(): Collection
    {
        return Role::query()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Role $role) => $this->mapRole($role))
            ->values();
    }

    private function mapRole(Role $role): array
    {
        return [
            'role' => $role->key,
            'roleTitle' => $role->label,
            'division' => $role->division,
            'description' => $role->description,
            'isActive' => (bool) $role->is_active,
            'sortOrder' => (int) $role->sort_order,
        ];
    }

    private function logAudit(Request $request, string $action, string $target, string $details): void
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
