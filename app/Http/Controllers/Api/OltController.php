<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Olt;
use App\Models\Odp;
use App\Services\ActivityLogService;
use App\Services\OltRealFetcherService;
use App\Services\OltService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OltController extends Controller
{
    protected OltService $oltService;
    protected OltRealFetcherService $oltFetcher;

    public function __construct(
        OltService $oltService,
        OltRealFetcherService $oltFetcher
    ) {
        $this->oltService = $oltService;
        $this->oltFetcher = $oltFetcher;
    }

    public function index(): JsonResponse
    {
        $olts = Olt::orderBy('name', 'asc')->get();
        return response()->json([
            'success' => true,
            'data'    => $olts,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:100',
            'brand'          => 'required|string|max:50',
            'type'           => 'required|string|in:EPON,GPON',
            'pon_ports'      => 'required|integer|in:4,8,16',
            'ip_address'     => 'required|string|max:50',
            'snmp_port'      => 'nullable|integer',
            'snmp_community' => 'nullable|string|max:50',
            'telnet_port'    => 'nullable|integer',
            'web_port'       => 'nullable|integer',
            'username'       => 'nullable|string|max:50',
            'password'       => 'nullable|string|max:100',
            'location_name'  => 'nullable|string|max:100',
            'latitude'       => 'nullable|numeric',
            'longitude'      => 'nullable|numeric',
            'status'         => 'nullable|string|in:online,warning,offline',
            'notes'          => 'nullable|string',
        ]);

        $validated['pon_data'] = Olt::generateDefaultPonData($validated['type'], $validated['pon_ports']);
        $olt = Olt::create($validated);
        Olt::clearCache();

        ActivityLogService::log(
            'INFO',
            'Tambah OLT',
            "Menambahkan OLT {$olt->name} ({$olt->brand} {$olt->type} - {$olt->ip_address})",
            $request->user()?->nama ?? 'Admin'
        );

        return response()->json([
            'success' => true,
            'message' => 'OLT berhasil ditambahkan.',
            'data'    => $olt,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $olt = Olt::findOrFail($id);
        return response()->json([
            'success'      => true,
            'olt'          => $olt,
            'pon_data'     => $olt->pon_data ?? [],
            'availability' => $olt->availability,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $olt = Olt::findOrFail($id);

        $validated = $request->validate([
            'name'           => 'required|string|max:100',
            'brand'          => 'required|string|max:50',
            'type'           => 'required|string|in:EPON,GPON',
            'pon_ports'      => 'required|integer|in:4,8,16',
            'ip_address'     => 'required|string|max:50',
            'snmp_port'      => 'nullable|integer',
            'snmp_community' => 'nullable|string|max:50',
            'telnet_port'    => 'nullable|integer',
            'web_port'       => 'nullable|integer',
            'username'       => 'nullable|string|max:50',
            'password'       => 'nullable|string|max:100',
            'location_name'  => 'nullable|string|max:100',
            'latitude'       => 'nullable|numeric',
            'longitude'      => 'nullable|numeric',
            'status'         => 'nullable|string|in:online,warning,offline',
            'notes'          => 'nullable|string',
        ]);

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $olt->update($validated);
        Olt::clearCache();

        ActivityLogService::log(
            'INFO',
            'Update OLT',
            "Memperbarui OLT {$olt->name}",
            $request->user()?->nama ?? 'Admin'
        );

        return response()->json([
            'success' => true,
            'message' => 'OLT berhasil diperbarui.',
            'data'    => $olt,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $olt = Olt::findOrFail($id);
        $name = $olt->name;
        $olt->delete();
        Olt::clearCache();

        ActivityLogService::log(
            'WARNING',
            'Hapus OLT',
            "Menghapus OLT {$name}",
            $request->user()?->nama ?? 'Admin'
        );

        return response()->json([
            'success' => true,
            'message' => "OLT {$name} berhasil dihapus.",
        ]);
    }

    public function telemetry(Request $request): JsonResponse
    {
        $useCache = !$request->boolean('fresh');
        $summary = $this->oltService->getSummary($useCache);
        $markers = $this->oltService->getMapMarkers();
        $olts = Olt::all();
        $odps = Odp::with('olt')->get();

        return response()->json([
            'summary' => $summary,
            'markers' => $markers,
            'olts'    => $olts,
            'odps'    => $odps,
        ]);
    }

    public function ping(int $id): JsonResponse
    {
        $result = $this->oltService->pingOlt($id);
        return response()->json($result);
    }

    public function sync(int $id): JsonResponse
    {
        $olt = Olt::find($id);
        if (!$olt) {
            return response()->json(['success' => false, 'message' => 'OLT tidak ditemukan.'], 404);
        }

        $result = $this->oltFetcher->fetchRealData($olt);
        return response()->json($result);
    }

    public function syncAll(): JsonResponse
    {
        $olts = Olt::all();
        $results = [];

        foreach ($olts as $olt) {
            $results[$olt->id] = $this->oltFetcher->fetchRealData($olt);
        }

        return response()->json([
            'success' => true,
            'message' => 'Sinkronisasi seluruh OLT selesai.',
            'results' => $results,
        ]);
    }

    public function onus(Request $request, int $id): JsonResponse
    {
        $olt = Olt::find($id);
        if (!$olt) {
            return response()->json(['success' => false, 'message' => 'OLT tidak ditemukan.'], 404);
        }

        $port = $request->has('port') && is_numeric($request->input('port')) ? (int) $request->input('port') : null;
        $fresh = $request->boolean('fresh', false);

        $data = $this->oltFetcher->fetchDetailedOnus($olt, $port, $fresh);
        return response()->json($data);
    }

    public function restartOnu(Request $request, int $id): JsonResponse
    {
        $olt = Olt::find($id);
        if (!$olt) {
            return response()->json(['success' => false, 'message' => 'OLT tidak ditemukan.'], 404);
        }

        $portId = (int) $request->input('port_id', 1);
        $onuId  = (int) $request->input('onu_id', 1);
        $mac    = (string) $request->input('mac_or_sn', '');

        $result = $this->oltFetcher->restartOnu($olt, $portId, $onuId, $mac);
        return response()->json($result);
    }

    public function deleteOnu(Request $request, int $id): JsonResponse
    {
        $olt = Olt::find($id);
        if (!$olt) {
            return response()->json(['success' => false, 'message' => 'OLT tidak ditemukan.'], 404);
        }

        $portId = (int) $request->input('port_id', 1);
        $onuId  = (int) $request->input('onu_id', 1);
        $mac    = (string) $request->input('mac_or_sn', '');

        $result = $this->oltFetcher->deleteOnu($olt, $portId, $onuId, $mac);
        return response()->json($result);
    }
}
