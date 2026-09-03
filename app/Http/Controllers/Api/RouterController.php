<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Services\ActivityLogService;
use App\Services\MikrotikService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RouterController extends Controller
{
    public function index(): JsonResponse
    {
        $routers = Router::orderBy('is_default', 'desc')->orderBy('name', 'asc')->get();
        return response()->json([
            'success' => true,
            'data'    => $routers,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:100',
            'ip_address'      => 'required|string|max:50',
            'port'            => 'nullable|integer',
            'username'        => 'required|string|max:50',
            'password'        => 'required|string|max:100',
            'type'            => 'nullable|string|in:core,crs,switch,ccr',
            'model'           => 'nullable|string|max:100',
            'wan_interface'   => 'nullable|string|max:50',
            'pppoe_interface' => 'nullable|string|max:50',
            'is_active'       => 'nullable|boolean',
            'is_default'      => 'nullable|boolean',
            'notes'           => 'nullable|string',
        ]);

        if (!empty($validated['is_default'])) {
            Router::where('is_default', true)->update(['is_default' => false]);
        }

        $router = Router::create($validated);

        ActivityLogService::log(
            'INFO',
            'Tambah Router',
            "Menambahkan router {$router->name} ({$router->ip_address})",
            $request->user()?->nama ?? 'Admin'
        );

        return response()->json([
            'success' => true,
            'message' => 'Router berhasil ditambahkan.',
            'data'    => $router,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        return response()->json([
            'success' => true,
            'data'    => $router,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $router = Router::findOrFail($id);

        $validated = $request->validate([
            'name'            => 'required|string|max:100',
            'ip_address'      => 'required|string|max:50',
            'port'            => 'nullable|integer',
            'username'        => 'required|string|max:50',
            'password'        => 'nullable|string|max:100',
            'type'            => 'nullable|string|in:core,crs,switch,ccr',
            'model'           => 'nullable|string|max:100',
            'wan_interface'   => 'nullable|string|max:50',
            'pppoe_interface' => 'nullable|string|max:50',
            'is_active'       => 'nullable|boolean',
            'is_default'      => 'nullable|boolean',
            'notes'           => 'nullable|string',
        ]);

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        if (!empty($validated['is_default'])) {
            Router::where('id', '!=', $id)->where('is_default', true)->update(['is_default' => false]);
        }

        $router->update($validated);

        ActivityLogService::log(
            'INFO',
            'Update Router',
            "Memperbarui router {$router->name} ({$router->ip_address})",
            $request->user()?->nama ?? 'Admin'
        );

        return response()->json([
            'success' => true,
            'message' => 'Router berhasil diperbarui.',
            'data'    => $router,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        $name = $router->name;
        $router->delete();

        ActivityLogService::log(
            'WARNING',
            'Hapus Router',
            "Menghapus router {$name}",
            $request->user()?->nama ?? 'Admin'
        );

        return response()->json([
            'success' => true,
            'message' => "Router {$name} berhasil dihapus.",
        ]);
    }

    public function setDefault(int $id): JsonResponse
    {
        Router::where('is_default', true)->update(['is_default' => false]);
        $router = Router::findOrFail($id);
        $router->update(['is_default' => true]);

        return response()->json([
            'success' => true,
            'message' => "Router {$router->name} dijadikan default router.",
            'data'    => $router,
        ]);
    }
}
