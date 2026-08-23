<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppNavigationModuleResource;
use App\Http\Resources\NavigationHeadResource;
use App\Http\Resources\RoleModuleMappingResource;
use App\Models\AuditLog;
use App\Models\NavigationHead;
use App\Models\RoleModuleMapping;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminNavigationConfigController extends Controller
{
    private const HEAD_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function index()
    {
        return response()->json([
            'data' => [
                'heads' => NavigationHeadResource::collection(
                    NavigationHead::query()->orderBy('sort_order')->get()
                ),
                'modules' => AppNavigationModuleResource::collection(
                    \App\Models\AppNavigationModule::query()->orderBy('sort_order')->get()
                ),
                'roleMappings' => RoleModuleMappingResource::collection(
                    RoleModuleMapping::query()->orderBy('role')->orderBy('module_key')->get()
                ),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $payload = $request->validate(
            [
                'heads' => ['required', 'array', 'min:1'],
                'heads.*.key' => ['required', 'string', 'max:255', 'regex:'.self::HEAD_KEY_PATTERN],
                'heads.*.label' => ['required', 'string', 'max:255'],
                'heads.*.order' => ['required', 'integer', 'min:0'],
                'heads.*.is_active' => ['boolean'],
            ],
            [
                'heads.required' => 'Minimal harus ada satu kepala navigasi.',
                'heads.array' => 'Format kepala navigasi tidak valid.',
                'heads.min' => 'Minimal harus ada satu kepala navigasi.',
                'heads.*.key.required' => 'Kode kepala navigasi wajib diisi.',
                'heads.*.key.regex' => 'Kode kepala navigasi hanya boleh huruf kecil, angka, dan underscore, serta harus diawali huruf.',
                'heads.*.label.required' => 'Label kepala navigasi wajib diisi.',
                'heads.*.order.required' => 'Urutan kepala navigasi wajib diisi.',
            ]
        );

        $providedKeys = collect($payload['heads'])->pluck('key');

        foreach ($payload['heads'] as $head) {
            NavigationHead::query()->updateOrCreate(
                ['key' => $head['key']],
                [
                    'label' => $head['label'],
                    'sort_order' => $head['order'],
                    'is_active' => $head['is_active'] ?? true,
                ]
            );
        }

        NavigationHead::query()->whereNotIn('key', $providedKeys)->delete();

        $actor = $request->user();
        AuditLog::query()->create([
            'id' => 'LOG-'.Str::upper(Str::random(8)),
            'timestamp' => now(),
            'actor_name' => $actor->name,
            'actor_role' => $actor->role,
            'action' => 'Update Navigation Heads',
            'target' => 'navigation_heads',
            'details' => 'Kepala navigasi diperbarui oleh superadmin.',
            'type' => 'info',
        ]);

        return $this->index();
    }
}
