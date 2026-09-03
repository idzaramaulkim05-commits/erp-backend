<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class MasterWilayah extends Model
{
    use HasFactory;

    protected $table = 'master_wilayah';

    protected $fillable = [
        'provinsi_kode',
        'provinsi_nama',
        'kabupaten_kode',
        'kabupaten_nama',
        'kecamatan_kode',
        'kecamatan_nama',
        'desa_kode',
        'desa_nama',
        'kode_wilayah_full',
    ];

    protected static ?\Illuminate\Database\Eloquent\Collection $cachedCollection = null;

    public static function getCachedAll(): \Illuminate\Database\Eloquent\Collection
    {
        if (static::$cachedCollection !== null) {
            return static::$cachedCollection;
        }

        return static::$cachedCollection = static::orderBy('provinsi_nama')
            ->orderBy('kabupaten_nama')
            ->orderBy('kecamatan_nama')
            ->orderBy('desa_nama')
            ->get();
    }

    public static function clearCache(): void
    {
        static::$cachedCollection = null;
    }

    /**
     * Generate sequential Customer ID based on location code + 4-digit global sequential counter.
     */
    public static function generateCustomerId(string $kodeWilayahFull): string
    {
        $kodeWilayahFull = trim($kodeWilayahFull);
        
        if (preg_match('/^(18\d{8})/', $kodeWilayahFull, $m)) {
            $kodeWilayahFull = $m[1];
        } elseif (strlen($kodeWilayahFull) > 10) {
            $kodeWilayahFull = substr($kodeWilayahFull, 0, 10);
        } elseif (empty($kodeWilayahFull)) {
            $kodeWilayahFull = '1803100013';
        }

        $maxGlobalSeq = 0;

        // 1. Scan tickets table
        if (\Illuminate\Support\Facades\Schema::hasTable('tickets')) {
            $ticketIds = DB::table('tickets')
                ->whereNotNull('id_customer')
                ->where('id_customer', '<>', '')
                ->pluck('id_customer');
            foreach ($ticketIds as $id) {
                $trimmed = trim((string)$id);
                if (preg_match('/^\d{10}(\d{4})$/', $trimmed, $match)) {
                    $seq = (int) $match[1];
                    if ($seq > $maxGlobalSeq) $maxGlobalSeq = $seq;
                }
            }
        }

        // 2. Scan pelanggan table
        if (\Illuminate\Support\Facades\Schema::hasTable('pelanggan')) {
            $pelangganIds = DB::table('pelanggan')
                ->whereNotNull('id_customer')
                ->where('id_customer', '<>', '')
                ->pluck('id_customer');
            foreach ($pelangganIds as $id) {
                $trimmed = trim((string)$id);
                if (preg_match('/^\d{10}(\d{4})$/', $trimmed, $match)) {
                    $seq = (int) $match[1];
                    if ($seq > $maxGlobalSeq) $maxGlobalSeq = $seq;
                }
            }
        }

        // 3. Scan data_sheets table
        if (\Illuminate\Support\Facades\Schema::hasTable('data_sheets')) {
            $dataSheetUsernames = DB::table('data_sheets')
                ->whereNotNull('username_pppoe')
                ->pluck('username_pppoe');
            foreach ($dataSheetUsernames as $un) {
                $trimmed = trim((string)$un);
                if (preg_match('/^\d{10}(\d{4})(@|$)/', $trimmed, $match)) {
                    $seq = (int) $match[1];
                    if ($seq > $maxGlobalSeq) $maxGlobalSeq = $seq;
                }
            }
        }

        $nextSeq = $maxGlobalSeq + 1;

        return sprintf('%s%04d', $kodeWilayahFull, $nextSeq);
    }
}
