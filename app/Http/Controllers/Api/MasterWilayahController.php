<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterWilayah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MasterWilayahController extends Controller
{
    public function all(): JsonResponse
    {
        $data = Cache::remember('master_wilayah_json_v2', 86400, function () {
            return MasterWilayah::select([
                'provinsi_kode', 'provinsi_nama',
                'kabupaten_kode', 'kabupaten_nama',
                'kecamatan_kode', 'kecamatan_nama',
                'desa_kode', 'desa_nama',
                'kode_wilayah_full'
            ])->orderBy('provinsi_nama')->orderBy('kabupaten_nama')->orderBy('kecamatan_nama')->orderBy('desa_nama')->get();
        });

        return response()->json($data)->header('Cache-Control', 'public, max-age=86400');
    }

    public function generateId(Request $request): JsonResponse
    {
        $kode = $request->input('kode', '1803100013');
        $namaDepan = $request->input('nama_depan', '');
        $customerId = MasterWilayah::generateCustomerId($kode);
        $namaSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $namaDepan));
        $usernamePppoe = $namaSlug ? "{$customerId}@{$namaSlug}" : "{$customerId}@user";

        return response()->json([
            'customer_id'    => $customerId,
            'username_pppoe' => $usernamePppoe,
            'password_pppoe' => '1',
        ]);
    }
}
