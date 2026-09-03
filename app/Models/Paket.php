<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Paket extends Model
{
    use HasFactory;

    protected $table = 'pakets';

    protected $fillable = [
        'nama_paket',
        'kecepatan',
        'allow_upgrade_downgrade',
        'allow_online_register',
        'harga_dasar',
        'ppn',
        'tarif_bulanan',
        'komisi_agen',
        'router_id',
        'mikrotik_profile',
        'keterangan',
        'is_active',
    ];

    protected $casts = [
        'kecepatan'     => 'integer',
        'harga_dasar'   => 'decimal:2',
        'ppn'           => 'decimal:2',
        'tarif_bulanan' => 'decimal:2',
        'komisi_agen'   => 'decimal:2',
        'is_active'     => 'boolean',
    ];

    protected static ?\Illuminate\Database\Eloquent\Collection $cachedActivePakets = null;

    public static function getActivePakets(): \Illuminate\Database\Eloquent\Collection
    {
        if (static::$cachedActivePakets !== null) {
            return static::$cachedActivePakets;
        }

        return static::$cachedActivePakets = static::where('is_active', true)->get();
    }

    public static function clearCache(): void
    {
        static::$cachedActivePakets = null;
    }

    /**
     * Relationship to Router.
     */
    public function router()
    {
        return $this->belongsTo(Router::class, 'router_id');
    }

    public function getFormattedTarifAttribute(): string
    {
        return 'Rp ' . number_format($this->tarif_bulanan ?: 0, 0, ',', '.');
    }

    public function getFormattedHargaDasarAttribute(): string
    {
        return 'Rp ' . number_format($this->harga_dasar ?: 0, 0, ',', '.');
    }

    public function getFormattedKomisiAttribute(): string
    {
        return 'Rp ' . number_format($this->komisi_agen ?: 0, 0, ',', '.');
    }
}
