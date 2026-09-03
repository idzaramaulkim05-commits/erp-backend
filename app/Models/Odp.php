<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Odp extends Model
{
    protected $table = 'odps';

    protected $fillable = [
        'olt_id',
        'nama_odp',
        'kode_odp',
        'lokasi',
        'pon_port',
        'kapasitas',
        'total_pelanggan',
        'online_pelanggan',
        'offline_pelanggan',
        'status', // normal, fiber_cut, power_off, mati_lampu, warning_redaman
        'keterangan_gangguan',
        'parent_odc',
        'latitude',
        'longitude',
        'ports_data',
        'notes',
    ];

    protected $casts = [
        'latitude'          => 'float',
        'longitude'         => 'float',
        'pon_port'          => 'integer',
        'kapasitas'         => 'integer',
        'total_pelanggan'   => 'integer',
        'online_pelanggan'  => 'integer',
        'offline_pelanggan' => 'integer',
        'ports_data'        => 'array',
    ];

    protected static ?\Illuminate\Database\Eloquent\Collection $cachedCollection = null;

    public static function getCachedAll(): \Illuminate\Database\Eloquent\Collection
    {
        return \Illuminate\Support\Facades\Cache::remember('odps_cached_all_collection', 120, function () {
            return static::with('olt')->orderBy('nama_odp', 'asc')->get();
        });
    }

    public static function clearCache(): void
    {
        static::$cachedCollection = null;
        \Illuminate\Support\Facades\Cache::forget('odps_cached_all_collection');
    }

    /**
     * Parent OLT device relationship.
     */
    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class, 'olt_id');
    }
}
