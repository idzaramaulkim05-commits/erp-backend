<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppNavigationModuleResource;
use App\Http\Resources\NavigationHeadResource;
use App\Http\Resources\RoleMetaResource;
use App\Http\Resources\RoleModuleMappingResource;
use App\Models\AppNavigationModule;
use App\Models\AuditLog;
use App\Models\NavigationHead;
use App\Models\Role;
use App\Models\RoleModuleMapping;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminRoleModuleMappingController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => [
                'roles' => RoleMetaResource::collection($this->buildRoleMetaCollection()),
                'heads' => NavigationHeadResource::collection(NavigationHead::query()->orderBy('sort_order')->get()),
                'modules' => AppNavigationModuleResource::collection(
                    AppNavigationModule::query()->where('show_in_navbar', true)->orderBy('sort_order')->get()
                ),
                'mappings' => RoleModuleMappingResource::collection(
                    RoleModuleMapping::query()->orderBy('role')->orderBy('module_key')->get()
                ),
            ],
        ]);
    }

    public function update(Request $request, string $role)
    {
        $request->validate([
            'mappings' => ['required', 'array'],
            'mappings.*.module_key' => ['required', 'string', 'exists:app_navigation_modules,module_key'],
            'mappings.*.is_visible' => ['required', 'boolean'],
            'mappings.*.order_override' => ['nullable', 'integer', 'min:0'],
        ]);

        abort_unless($this->buildRoleMetaCollection()->contains(fn (array $item) => $item['role'] === $role), 404, 'Role tidak ditemukan.');

        $moduleKeys = collect($request->input('mappings'))->pluck('module_key')->all();
        RoleModuleMapping::query()->where('role', $role)->whereNotIn('module_key', $moduleKeys)->delete();

        foreach ($request->input('mappings', []) as $mapping) {
            RoleModuleMapping::query()->updateOrCreate(
                [
                    'role' => $role,
                    'module_key' => $mapping['module_key'],
                ],
                [
                    'is_visible' => $mapping['is_visible'],
                    'order_override' => $mapping['order_override'] ?? null,
                ]
            );
        }

        $actor = $request->user();
        AuditLog::query()->create([
            'id' => 'LOG-'.Str::upper(Str::random(8)),
            'timestamp' => now(),
            'actor_name' => $actor->name,
            'actor_role' => $actor->role,
            'action' => 'Update Role Module Mapping',
            'target' => $role,
            'details' => 'Mapping modul terhadap role diperbarui oleh superadmin.',
            'type' => 'info',
        ]);

        return response()->json([
            'data' => RoleModuleMappingResource::collection(
                RoleModuleMapping::query()->where('role', $role)->orderBy('module_key')->get()
            ),
        ]);
    }

    private function buildRoleMetaCollection()
    {
        return Role::query()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Role $role) => [
                'role' => $role->key,
                'roleTitle' => $role->label,
                'division' => $role->division,
                'description' => $role->description,
                'isActive' => (bool) $role->is_active,
                'sortOrder' => (int) $role->sort_order,
            ])
            ->values();
    }
}
