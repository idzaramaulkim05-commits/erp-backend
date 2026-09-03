<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataSheet;
use App\Models\Pelanggan;
use App\Models\Router;
use App\Services\ActivityLogService;
use App\Services\BackboneService;
use App\Services\MikrotikService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NetworkTelemetryController extends Controller
{
    protected MikrotikService $mikrotik;
    protected BackboneService $backboneService;

    public function __construct(
        MikrotikService $mikrotik,
        BackboneService $backboneService
    ) {
        $this->mikrotik = $mikrotik;
        $this->backboneService = $backboneService;
    }

    public function telemetry(Request $request): JsonResponse
    {
        $useCache = !$request->boolean('fresh');
        $routerId = $request->query('router_id');
        $device = $routerId ? Router::find($routerId) : null;
        $service = new MikrotikService($device);
        $data = $service->getTelemetry($useCache);
        return response()->json($data);
    }

    public function traffic(Request $request): JsonResponse
    {
        $routerId = $request->query('router_id');
        $iface = $request->query('interface');
        $device = $routerId ? Router::find($routerId) : null;
        $service = new MikrotikService($device);
        $data = $service->getTraffic($iface);
        return response()->json($data);
    }

    public function routerInterfaces(Request $request): JsonResponse
    {
        $useCache = !$request->boolean('fresh');
        $routerId = $request->query('router_id');
        $device = $routerId ? Router::find($routerId) : null;
        $service = new MikrotikService($device);
        $data = $service->getInterfacesDetailed($useCache);
        return response()->json($data);
    }

    public function ping(Request $request): JsonResponse
    {
        $target = $request->input('target', '8.8.8.8');
        $routerId = $request->query('router_id') ?? $request->input('router_id');
        $device = $routerId ? Router::find($routerId) : null;
        $service = new MikrotikService($device);
        $data = $service->pingTarget($target);
        return response()->json($data);
    }

    public function pingTerminal(Request $request): JsonResponse
    {
        $target = (string) $request->input('target', '8.8.8.8');
        $count = (int) $request->input('count', 4);
        $size = (int) $request->input('size', 56);
        $routerId = $request->query('router_id') ?? $request->input('router_id');

        $device = $routerId ? Router::find($routerId) : null;
        $service = new MikrotikService($device);
        $result = $service->runPingTerminal($target, $count, $size);

        return response()->json($result);
    }

    public function backbone(Request $request): JsonResponse
    {
        $useCache = !$request->boolean('fresh');
        $data = $this->backboneService->getBackboneData($useCache);
        return response()->json($data);
    }

    public function pelangganList(Request $request): JsonResponse
    {
        $useCache = !$request->boolean('fresh');
        $routerId = $request->query('router_id');
        $device = $routerId ? Router::find($routerId) : Router::getDefaultRouter();
        $service = new MikrotikService($device);
        $data = $service->getPppoeSecrets($useCache);
        return response()->json($data);
    }

    public function togglePelangganStatus(Request $request): JsonResponse
    {
        $username = (string) $request->input('username', '');
        $enable = $request->boolean('enable', true);

        if (empty($username)) {
            return response()->json([
                'success' => false,
                'message' => 'Username PPPoE wajib diisi.',
            ], 422);
        }

        $routerId = $request->input('router_id');
        $device = $routerId ? Router::find($routerId) : null;
        $service = new MikrotikService($device);

        $result = $service->togglePppoeSecret($username, $enable);

        ActivityLogService::log(
            'INFO',
            $enable ? 'Un-isolir PPPoE' : 'Isolir PPPoE',
            "Mengubah status PPPoE {$username} menjadi " . ($enable ? 'AKTIF' : 'TERISOLIR'),
            $request->user()?->nama ?? 'Admin'
        );

        return response()->json($result);
    }

    public function deletePelanggan(Request $request): JsonResponse
    {
        $username = (string) $request->input('username', '');
        if (empty($username)) {
            return response()->json([
                'success' => false,
                'message' => 'Username PPPoE yang ingin dihapus wajib diisi.',
            ], 422);
        }

        $routerId = $request->input('router_id') ?: $request->query('router_id');
        $device = $routerId ? Router::find($routerId) : null;
        $service = new MikrotikService($device);

        $result = $service->deletePppoeSecret($username);

        if (!empty($result['success'])) {
            DataSheet::where('username_pppoe', $username)->delete();
            Pelanggan::where('username', $username)->delete();

            ActivityLogService::log(
                'WARNING',
                'Hapus Pelanggan PPPoE',
                "Menghapus pelanggan {$username} dari Secret MikroTik, Database & DataSheet",
                $request->user()?->nama ?? 'Admin'
            );
        }

        return response()->json($result);
    }
}
