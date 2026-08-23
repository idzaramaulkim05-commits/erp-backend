<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppNavigationModuleResource;
use App\Http\Resources\NavigationHeadResource;
use App\Models\AppNavigationModule;
use App\Models\AuditLog;
use App\Models\NavigationHead;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminModuleController extends Controller
{
    private const MODULE_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';
    private const ROUTE_TARGET_PATTERN = '/^\/app(?:\/[a-z0-9-]+)+$/';

    public function index()
    {
        return response()->json([
            'data' => [
                'heads' => NavigationHeadResource::collection(
                    NavigationHead::query()->orderBy('sort_order')->get()
                ),
                'modules' => AppNavigationModuleResource::collection(
                    AppNavigationModule::query()->orderBy('sort_order')->get()
                ),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'key' => ['required', 'string', 'regex:'.self::MODULE_KEY_PATTERN, 'unique:app_navigation_modules,module_key'],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'navigation_head_key' => ['required', 'string', 'exists:navigation_heads,key'],
            'order' => ['required', 'integer', 'min:0'],
            'route_target' => ['required', 'string', 'regex:'.self::ROUTE_TARGET_PATTERN, 'unique:app_navigation_modules,route_target'],
            'quick_action' => ['nullable', 'string', Rule::in(['new_ticket', 'new_customer', 'new_task', 'new_procurement'])],
            'view_formats' => ['array'],
            'view_formats.*' => ['string', Rule::in(['table', 'grid', 'kanban', 'map'])],
            'is_active' => ['boolean'],
            'show_in_navbar' => ['boolean'],
            'admin_only_dashboard' => ['boolean'],
        ], [
            'key.regex' => 'Key modul hanya boleh huruf kecil, angka, dan underscore, serta harus diawali huruf.',
            'navigation_head_key.exists' => 'Kepala navigasi yang dipilih tidak valid. Simpan atau perbaiki data head navigasi terlebih dahulu.',
            'route_target.regex' => 'Route target harus berupa path internal yang diawali /app/, misalnya /app/helpdesk.',
            'route_target.unique' => 'Route target sudah dipakai modul lain.',
        ]);

        $showInNavbar = (bool) ($payload['show_in_navbar'] ?? true);
        $adminOnlyDashboard = (bool) ($payload['admin_only_dashboard'] ?? false);

        if ($adminOnlyDashboard) {
            $showInNavbar = false;
        }

        $module = AppNavigationModule::query()->create([
            'module_key' => $payload['key'],
            'label' => $payload['label'],
            'description' => $payload['description'] ?? null,
            'route_target' => $payload['route_target'],
            'navigation_head_key' => $payload['navigation_head_key'],
            'sort_order' => $payload['order'],
            'quick_action' => $payload['quick_action'] ?? null,
            'view_formats' => $payload['view_formats'] ?? ['table'],
            'is_active' => $payload['is_active'] ?? true,
            'show_in_navbar' => $showInNavbar,
            'admin_only_dashboard' => $adminOnlyDashboard,
        ]);

        $this->logAdminEvent($request, 'Create Navigation Module', $module->module_key, 'Master modul navigasi dibuat.');

        return AppNavigationModuleResource::make($module)->response()->setStatusCode(201);
    }

    public function update(Request $request, string $moduleKey)
    {
        $module = AppNavigationModule::query()->findOrFail($moduleKey);

        $payload = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'navigation_head_key' => ['required', 'string', 'exists:navigation_heads,key'],
            'order' => ['required', 'integer', 'min:0'],
            'route_target' => ['required', 'string', 'regex:'.self::ROUTE_TARGET_PATTERN, Rule::unique('app_navigation_modules', 'route_target')->ignore($module->module_key, 'module_key')],
            'quick_action' => ['nullable', 'string', Rule::in(['new_ticket', 'new_customer', 'new_task', 'new_procurement'])],
            'view_formats' => ['array'],
            'view_formats.*' => ['string', Rule::in(['table', 'grid', 'kanban', 'map'])],
            'is_active' => ['boolean'],
            'show_in_navbar' => ['boolean'],
            'admin_only_dashboard' => ['boolean'],
        ], [
            'navigation_head_key.exists' => 'Kepala navigasi yang dipilih tidak valid. Simpan atau perbaiki data head navigasi terlebih dahulu.',
            'route_target.regex' => 'Route target harus berupa path internal yang diawali /app/, misalnya /app/helpdesk.',
            'route_target.unique' => 'Route target sudah dipakai modul lain.',
        ]);

        $showInNavbar = array_key_exists('show_in_navbar', $payload) ? (bool) $payload['show_in_navbar'] : $module->show_in_navbar;
        $adminOnlyDashboard = array_key_exists('admin_only_dashboard', $payload) ? (bool) $payload['admin_only_dashboard'] : $module->admin_only_dashboard;

        if ($adminOnlyDashboard) {
            $showInNavbar = false;
        }

        $module->update([
            'label' => $payload['label'],
            'description' => $payload['description'] ?? null,
            'route_target' => $payload['route_target'],
            'navigation_head_key' => $payload['navigation_head_key'],
            'sort_order' => $payload['order'],
            'quick_action' => $payload['quick_action'] ?? null,
            'view_formats' => $payload['view_formats'] ?? ['table'],
            'is_active' => $payload['is_active'] ?? true,
            'show_in_navbar' => $showInNavbar,
            'admin_only_dashboard' => $adminOnlyDashboard,
        ]);

        $this->logAdminEvent($request, 'Update Navigation Module', $module->module_key, 'Master modul navigasi diperbarui.');

        return AppNavigationModuleResource::make($module->fresh());
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
