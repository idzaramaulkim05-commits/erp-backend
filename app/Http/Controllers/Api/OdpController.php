<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Odp;
use App\Models\Olt;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class OdpController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'all');
        $oltId = $request->input('olt_id', 'all');
        $q = trim((string)$request->input('q', ''));

        $query = Odp::with('olt')->orderBy('nama_odp', 'asc');
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($oltId !== 'all' && is_numeric($oltId)) {
            $query->where('olt_id', $oltId);
        }
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('nama_odp', 'like', "%{$q}%")
                  ->orWhere('lokasi', 'like', "%{$q}%")
                  ->orWhere('parent_odc', 'like', "%{$q}%");
            });
        }
        $odps = $query->get();

        $odpCounts = Odp::select('status', \Illuminate\Support\Facades\DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $stats = [
            'total'           => array_sum($odpCounts),
            'normal'          => $odpCounts['normal'] ?? 0,
            'fiber_cut'       => $odpCounts['fiber_cut'] ?? 0,
            'power_off'       => $odpCounts['power_off'] ?? 0,
            'mati_lampu'      => $odpCounts['mati_lampu'] ?? 0,
            'warning_redaman' => $odpCounts['warning_redaman'] ?? 0,
        ];

        return response()->json([
            'success' => true,
            'stats'   => $stats,
            'odps'    => $odps,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nama_odp'            => 'required|string|max:100|unique:odps,nama_odp',
            'kode_odp'            => 'nullable|string|max:50',
            'lokasi'              => 'required|string|max:100',
            'olt_id'              => 'nullable|exists:olts,id',
            'pon_port'            => 'required|integer|min:1|max:16',
            'kapasitas'           => 'required|integer|in:4,8,16,24',
            'total_pelanggan'     => 'nullable|integer|min:0',
            'online_pelanggan'    => 'nullable|integer|min:0',
            'offline_pelanggan'   => 'nullable|integer|min:0',
            'status'              => 'required|string|in:normal,fiber_cut,power_off,mati_lampu,warning_redaman',
            'keterangan_gangguan' => 'nullable|string|max:255',
            'parent_odc'          => 'nullable|string|max:100',
            'latitude'            => 'nullable|numeric',
            'longitude'           => 'nullable|numeric',
            'notes'               => 'nullable|string|max:500',
        ]);

        $validated['total_pelanggan'] = $validated['total_pelanggan'] ?? $validated['kapasitas'];
        $validated['online_pelanggan'] = $validated['online_pelanggan'] ?? $validated['total_pelanggan'];
        $validated['offline_pelanggan'] = $validated['offline_pelanggan'] ?? 0;

        $odp = Odp::create($validated);
        Odp::clearCache();

        ActivityLogService::log(
            'INFO',
            'Tambah ODP',
            "Menambahkan titik ODP baru: {$odp->nama_odp} ({$odp->lokasi})",
            $request->user()?->nama ?? 'Admin'
        );

        return response()->json([
            'success' => true,
            'message' => "Titik ODP '{$odp->nama_odp}' berhasil ditambahkan.",
            'odp'     => $odp,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $odp = Odp::with('olt')->findOrFail($id);
        return response()->json([
            'success' => true,
            'odp'     => $odp,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $odp = Odp::findOrFail($id);

        $validated = $request->validate([
            'nama_odp'            => "required|string|max:100|unique:odps,nama_odp,{$id}",
            'kode_odp'            => 'nullable|string|max:50',
            'lokasi'              => 'required|string|max:100',
            'olt_id'              => 'nullable|exists:olts,id',
            'pon_port'            => 'required|integer|min:1|max:16',
            'kapasitas'           => 'required|integer|in:4,8,16,24',
            'total_pelanggan'     => 'nullable|integer|min:0',
            'online_pelanggan'    => 'nullable|integer|min:0',
            'offline_pelanggan'   => 'nullable|integer|min:0',
            'status'              => 'required|string|in:normal,fiber_cut,power_off,mati_lampu,warning_redaman',
            'keterangan_gangguan' => 'nullable|string|max:255',
            'parent_odc'          => 'nullable|string|max:100',
            'latitude'            => 'nullable|numeric',
            'longitude'           => 'nullable|numeric',
            'notes'               => 'nullable|string|max:500',
        ]);

        $oldStatus = $odp->status;
        $odp->update($validated);
        Odp::clearCache();

        ActivityLogService::log(
            'WARNING',
            'Edit ODP',
            "Memperbarui data ODP: {$odp->nama_odp} (Status: {$odp->status})",
            $request->user()?->nama ?? 'Admin'
        );

        if ($odp->status !== 'normal' && $odp->status !== $oldStatus) {
            NotificationService::notifyOdpFailure($odp, $odp->status, $odp->keterangan_gangguan);
        }

        return response()->json([
            'success' => true,
            'message' => "Data ODP '{$odp->nama_odp}' berhasil diperbarui.",
            'odp'     => $odp,
        ]);
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $odp = Odp::findOrFail($id);
        $status = $request->input('status', 'normal');
        $reason = $request->input('keterangan', '');

        $oldStatus = $odp->status;
        $odp->status = $status;
        if ($status === 'fiber_cut') {
            $odp->offline_pelanggan = $request->input('offline', 1);
            $odp->online_pelanggan = max(0, $odp->total_pelanggan - $odp->offline_pelanggan);
            $odp->keterangan_gangguan = $reason ?: 'LOS / Kabel Fiber Cut Terputus';
        } elseif ($status === 'power_off') {
            $odp->offline_pelanggan = $request->input('offline', 1);
            $odp->online_pelanggan = max(0, $odp->total_pelanggan - $odp->offline_pelanggan);
            $odp->keterangan_gangguan = $reason ?: 'Adaptor Dicabut / Dying Gasp';
        } elseif ($status === 'mati_lampu') {
            $odp->offline_pelanggan = $odp->total_pelanggan;
            $odp->online_pelanggan = 0;
            $odp->keterangan_gangguan = $reason ?: 'Mati Lampu PLN Area Padam';
        } else {
            $odp->offline_pelanggan = 0;
            $odp->online_pelanggan = $odp->total_pelanggan;
            $odp->keterangan_gangguan = 'Semua Pelanggan Normal';
        }

        $odp->save();
        Odp::clearCache();

        if ($odp->status !== 'normal') {
            NotificationService::notifyOdpFailure($odp, $odp->status, $odp->keterangan_gangguan);
        } elseif ($oldStatus !== 'normal') {
            NotificationService::notifyOdpRecovery($odp);
        }

        return response()->json([
            'success' => true,
            'message' => "Status ODP '{$odp->nama_odp}' diubah menjadi {$odp->status}.",
            'odp'     => $odp,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $odp = Odp::findOrFail($id);
        $name = $odp->nama_odp;
        $odp->delete();
        Odp::clearCache();

        ActivityLogService::log(
            'WARNING',
            'Hapus ODP',
            "Menghapus titik ODP: {$name}",
            $request->user()?->nama ?? 'Admin'
        );

        return response()->json([
            'success' => true,
            'message' => "Titik ODP '{$name}' berhasil dihapus.",
        ]);
    }

    public function importKmz(Request $request): JsonResponse
    {
        $odps = $request->input('odps', []);
        $defaultOltId = $request->input('default_olt_id');
        $defaultPon = (int) ($request->input('default_pon_port') ?: 1);

        if (empty($odps) || !is_array($odps)) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada data titik ODP yang valid untuk diimpor.',
            ], 422);
        }

        $imported = 0;
        $updated = 0;

        foreach ($odps as $item) {
            $name = trim($item['name'] ?? '');
            $lat = isset($item['latitude']) ? (float) $item['latitude'] : null;
            $lng = isset($item['longitude']) ? (float) $item['longitude'] : null;

            if (!$name || !$lat || !$lng) {
                continue;
            }

            $oltId = !empty($item['olt_id']) ? $item['olt_id'] : $defaultOltId;
            $ponPort = !empty($item['pon_port']) ? (int) $item['pon_port'] : $defaultPon;
            $kapasitas = !empty($item['kapasitas']) ? (int) $item['kapasitas'] : 8;
            $lokasi = !empty($item['lokasi']) ? $item['lokasi'] : (!empty($item['description']) ? strip_tags($item['description']) : 'Jalur Distribusi EONET');
            $parentOdc = !empty($item['parent_odc']) ? $item['parent_odc'] : 'ODC-DISTRIBUSI';

            $existing = Odp::where('nama_odp', $name)->first();
            if ($existing) {
                $existing->update([
                    'latitude'   => $lat,
                    'longitude'  => $lng,
                    'lokasi'     => $lokasi ?: $existing->lokasi,
                    'olt_id'     => $oltId ?: $existing->olt_id,
                    'pon_port'   => $ponPort ?: $existing->pon_port,
                    'kapasitas'  => $kapasitas ?: $existing->kapasitas,
                    'parent_odc' => $parentOdc ?: $existing->parent_odc,
                ]);
                $updated++;
            } else {
                Odp::create([
                    'nama_odp'          => $name,
                    'kode_odp'          => $item['kode_odp'] ?? null,
                    'lokasi'            => $lokasi,
                    'olt_id'            => $oltId,
                    'pon_port'          => $ponPort,
                    'kapasitas'         => $kapasitas,
                    'total_pelanggan'   => $kapasitas,
                    'online_pelanggan'  => $kapasitas,
                    'offline_pelanggan' => 0,
                    'status'            => 'normal',
                    'parent_odc'        => $parentOdc,
                    'latitude'          => $lat,
                    'longitude'         => $lng,
                ]);
                $imported++;
            }
        }

        Odp::clearCache();

        ActivityLogService::log(
            'INFO',
            'Import Google Earth KML',
            "Mengimpor {$imported} ODP baru dan memperbarui {$updated} ODP dari file Google Earth KMZ",
            $request->user()?->nama ?? 'Admin'
        );

        return response()->json([
            'success'  => true,
            'message'  => "Berhasil mengimpor {$imported} titik ODP baru dan memperbarui {$updated} titik ODP yang sudah ada.",
            'imported' => $imported,
            'updated'  => $updated,
            'total'    => Odp::count(),
        ]);
    }
}
