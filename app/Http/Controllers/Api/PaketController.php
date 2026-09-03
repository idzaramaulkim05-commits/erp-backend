<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Paket;
use App\Models\Router;
use App\Services\ActivityLogService;
use App\Services\MikrotikService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaketController extends Controller
{
    public function index(): JsonResponse
    {
        $pakets = Paket::with('router')->orderBy('kecepatan', 'asc')->get();
        return response()->json([
            'success' => true,
            'data'    => $pakets,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nama_paket'              => 'required|string|max:150|unique:pakets,nama_paket',
            'kecepatan'               => 'required|integer|min:1',
            'allow_upgrade_downgrade' => 'nullable|string|in:YA,TIDAK',
            'allow_online_register'   => 'nullable|string|in:YA,TIDAK',
            'harga_dasar'             => 'required|numeric|min:0',
            'ppn'                     => 'nullable|numeric|min:0|max:100',
            'tarif_bulanan'           => 'required|numeric|min:0',
            'komisi_agen'             => 'nullable|numeric|min:0',
            'router_id'               => 'nullable|exists:routers,id',
            'mikrotik_profile'        => 'nullable|string|max:100',
            'keterangan'              => 'nullable|string',
            'is_active'               => 'nullable|boolean',
        ]);

        $paket = Paket::create($validated);
        Paket::clearCache();

        ActivityLogService::log(
            'INFO',
            'Tambah Paket Internet',
            "Menambahkan paket baru: {$paket->nama_paket} ({$paket->kecepatan} Mbps - Rp " . number_format($paket->tarif_bulanan, 0, ',', '.') . ")",
            $request->user()?->nama ?? 'Admin'
        );

        return response()->json([
            'success' => true,
            'message' => 'Paket internet berhasil ditambahkan.',
            'data'    => $paket,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $paket = Paket::with('router')->findOrFail($id);
        return response()->json([
            'success' => true,
            'data'    => $paket,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $paket = Paket::findOrFail($id);

        $validated = $request->validate([
            'nama_paket'              => "required|string|max:150|unique:pakets,nama_paket,{$id}",
            'kecepatan'               => 'required|integer|min:1',
            'allow_upgrade_downgrade' => 'nullable|string|in:YA,TIDAK',
            'allow_online_register'   => 'nullable|string|in:YA,TIDAK',
            'harga_dasar'             => 'required|numeric|min:0',
            'ppn'                     => 'nullable|numeric|min:0|max:100',
            'tarif_bulanan'           => 'required|numeric|min:0',
            'komisi_agen'             => 'nullable|numeric|min:0',
            'router_id'               => 'nullable|exists:routers,id',
            'mikrotik_profile'        => 'nullable|string|max:100',
            'keterangan'              => 'nullable|string',
            'is_active'               => 'nullable|boolean',
        ]);

        $paket->update($validated);
        Paket::clearCache();

        ActivityLogService::log(
            'INFO',
            'Update Paket Internet',
            "Memperbarui paket: {$paket->nama_paket}",
            $request->user()?->nama ?? 'Admin'
        );

        return response()->json([
            'success' => true,
            'message' => 'Paket internet berhasil diperbarui.',
            'data'    => $paket,
        ]);
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $paket = Paket::findOrFail($id);
        $paket->is_active = !$paket->is_active;
        $paket->save();
        Paket::clearCache();

        return response()->json([
            'success' => true,
            'message' => "Paket '{$paket->nama_paket}' status diubah menjadi " . ($paket->is_active ? 'Aktif' : 'Nonaktif') . '.',
            'data'    => $paket,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $paket = Paket::findOrFail($id);
        $name = $paket->nama_paket;
        $paket->delete();
        Paket::clearCache();

        ActivityLogService::log(
            'WARNING',
            'Hapus Paket Internet',
            "Menghapus paket: {$name}",
            $request->user()?->nama ?? 'Admin'
        );

        return response()->json([
            'success' => true,
            'message' => "Paket '{$name}' berhasil dihapus.",
        ]);
    }

    public function getProfilesApi(Request $request): JsonResponse
    {
        $routerId = $request->query('router_id');
        $device = $routerId ? Router::find($routerId) : null;
        $mikrotik = new MikrotikService($device);

        $client = $mikrotik->getClient();
        if (!$client) {
            return response()->json([
                'success'  => false,
                'message'  => 'MikroTik tidak terhubung atau offline.',
                'profiles' => ['default', 'default-encryption'],
            ]);
        }

        try {
            $query = new \RouterOS\Query('/ppp/profile/print');
            $profilesData = $client->query($query)->read();
            $profiles = [];

            foreach ($profilesData as $item) {
                if (!empty($item['name'])) {
                    $profiles[] = [
                        'name'         => $item['name'],
                        'rate_limit'   => $item['rate-limit'] ?? '-',
                        'local_addr'   => $item['local-address'] ?? '-',
                        'remote_addr'  => $item['remote-address'] ?? '-',
                        'only_one'     => $item['only-one'] ?? 'default',
                    ];
                }
            }

            return response()->json([
                'success'  => true,
                'profiles' => $profiles,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success'  => false,
                'message'  => 'Gagal membaca profile dari MikroTik: ' . $e->getMessage(),
                'profiles' => ['default'],
            ]);
        }
    }
}
