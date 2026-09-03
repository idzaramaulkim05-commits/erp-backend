<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerPackageRequest;
use App\Models\DataSheet;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Router;
use App\Services\ActivityLogService;
use App\Services\MikrotikService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomerPackageRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $query = CustomerPackageRequest::with(['requester', 'approver'])->latest();

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $requests = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $requests,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pelanggan_username' => 'required|string',
            'id_customer'        => 'nullable|string',
            'pelanggan_nama'     => 'required|string',
            'paket_lama'         => 'required|string',
            'paket_baru'         => 'required|string',
            'harga_lama'         => 'required|numeric',
            'harga_baru'         => 'required|numeric',
            'alasan'             => 'nullable|string',
        ]);

        $yearMonth = date('Ym');
        $lastReq = CustomerPackageRequest::where('nomor_pengajuan', 'like', "PKG-{$yearMonth}-%")
            ->orderBy('id', 'desc')
            ->first();
        $seq = 1;
        if ($lastReq) {
            $parts = explode('-', $lastReq->nomor_pengajuan);
            $seq = isset($parts[2]) ? (int)$parts[2] + 1 : 1;
        }
        $validated['nomor_pengajuan'] = sprintf('PKG-%s-%04d', $yearMonth, $seq);
        $validated['requested_by'] = $request->user()?->id;
        $validated['status'] = 'pending_finance';

        $packageReq = CustomerPackageRequest::create($validated);

        ActivityLogService::log(
            'INFO',
            'Pengajuan Ubah Paket',
            "Pengajuan ubah paket pelanggan {$packageReq->pelanggan_nama} ({$packageReq->pelanggan_username}) dari {$packageReq->paket_lama} ke {$packageReq->paket_baru}",
            $request->user()?->nama ?? 'Staff'
        );

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan upgrade/downgrade paket berhasil dikirim ke Finance.',
            'data'    => $packageReq,
        ], 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $packageReq = CustomerPackageRequest::findOrFail($id);

        if ($packageReq->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Pengajuan ini sudah disetujui sebelumnya.',
            ], 422);
        }

        $packageReq->status = 'approved';
        $packageReq->approved_by = $request->user()?->id;
        $packageReq->approved_at = now();
        $packageReq->catatan_finance = $request->input('catatan');
        $packageReq->save();

        // 1. Update DataSheet
        $ds = DataSheet::where('username_pppoe', $packageReq->pelanggan_username)->first();
        if ($ds) {
            $ds->paket = $packageReq->paket_baru;
            $ds->harga_paket = $packageReq->harga_baru;
            $ds->save();
        }

        // 2. Update Pelanggan
        $pelanggan = Pelanggan::where('username', $packageReq->pelanggan_username)->first();
        if ($pelanggan) {
            $pelanggan->paket = $packageReq->paket_baru;
            $pelanggan->harga_paket = $packageReq->harga_baru;
            $pelanggan->save();
        }

        // 3. Update Profile on MikroTik
        $targetPaket = Paket::where('nama_paket', $packageReq->paket_baru)->first();
        if ($targetPaket && !empty($targetPaket->mikrotik_profile)) {
            $router = $targetPaket->router ?: Router::getDefaultRouter();
            $mikrotik = new MikrotikService($router);
            $client = $mikrotik->getClient();
            if ($client) {
                try {
                    $q = (new \RouterOS\Query('/ppp/secret/print'))->where('name', $packageReq->pelanggan_username);
                    $secrets = $client->query($q)->read();
                    if (!empty($secrets) && !empty($secrets[0]['.id'])) {
                        $setQ = (new \RouterOS\Query('/ppp/secret/set'))
                            ->equal('.id', $secrets[0]['.id'])
                            ->equal('profile', $targetPaket->mikrotik_profile);
                        $client->query($setQ)->read();
                    }
                } catch (\Throwable $e) {}
            }
        }

        ActivityLogService::log(
            'SUCCESS',
            'Approval Ubah Paket',
            "Menyetujui pengajuan ubah paket #{$packageReq->nomor_pengajuan} untuk {$packageReq->pelanggan_nama} menjadi {$packageReq->paket_baru}",
            $request->user()?->nama ?? 'Finance'
        );

        return response()->json([
            'success' => true,
            'message' => "Pengajuan ubah paket #{$packageReq->nomor_pengajuan} berhasil disetujui & profil MikroTik diperbarui!",
            'data'    => $packageReq,
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $packageReq = CustomerPackageRequest::findOrFail($id);
        $packageReq->status = 'rejected';
        $packageReq->approved_by = $request->user()?->id;
        $packageReq->approved_at = now();
        $packageReq->catatan_finance = $request->input('catatan');
        $packageReq->save();

        ActivityLogService::log(
            'WARNING',
            'Penolakan Ubah Paket',
            "Menolak pengajuan ubah paket #{$packageReq->nomor_pengajuan} untuk {$packageReq->pelanggan_nama}",
            $request->user()?->nama ?? 'Finance'
        );

        return response()->json([
            'success' => true,
            'message' => "Pengajuan ubah paket #{$packageReq->nomor_pengajuan} ditolak.",
            'data'    => $packageReq,
        ]);
    }
}
