<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Router;
use App\Services\MikrotikService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string)$request->query('q', ''));
        $level = $request->query('level');
        $username = $request->query('username');

        $query = ActivityLog::latest('id');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('activity', 'like', "%{$q}%")
                  ->orWhere('detail', 'like', "%{$q}%")
                  ->orWhere('username', 'like', "%{$q}%")
                  ->orWhere('ip_address', 'like', "%{$q}%");
            });
        }

        if ($level && $level !== 'all') {
            $query->where('level', strtoupper($level));
        }

        if ($username) {
            $query->where('username', $username);
        }

        $logs = $query->paginate(25);

        return response()->json([
            'success' => true,
            'data'    => $logs,
        ]);
    }

    public function pppoeLogs(Request $request): JsonResponse
    {
        $routerId = $request->query('router_id');
        $device = $routerId ? Router::find($routerId) : null;
        $service = new MikrotikService($device);
        $data = $service->getPppoeLogs(false);
        return response()->json($data);
    }

    public function systemLogs(Request $request): JsonResponse
    {
        $routerId = $request->query('router_id');
        $device = $routerId ? Router::find($routerId) : null;
        $service = new MikrotikService($device);
        $data = $service->getSystemLogs(false);
        return response()->json($data);
    }
}
