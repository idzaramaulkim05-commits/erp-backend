<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WarehouseItem extends Model
{
    use HasFactory;

    protected $table = 'warehouse_items';

    protected $fillable = [
        'kode_barang',
        'nama_barang',
        'kategori',
        'kondisi',
        'satuan',
        'stok',
        'min_stok',
        'harga_estimasi',
        'lokasi_rak',
        'spesifikasi',
        'foto',
        'status',
    ];

    protected $casts = [
        'stok'           => 'integer',
        'min_stok'       => 'integer',
        'harga_estimasi' => 'decimal:2',
    ];

    public function requestItems()
    {
        return $this->hasMany(WarehouseRequestItem::class, 'warehouse_item_id');
    }

    public function mutations()
    {
        return $this->hasMany(WarehouseStockMutation::class, 'warehouse_item_id')->latest();
    }

    public function returns()
    {
        return $this->hasMany(WarehouseReturn::class, 'warehouse_item_id');
    }

    public function isLowStock(): bool
    {
        return $this->stok <= $this->min_stok;
    }

    public function getKondisiLabelAttribute(): string
    {
        return match ($this->kondisi) {
            'baru'   => 'Baru (Brand New)',
            'second' => 'Second (Bekas Layak Pakai)',
            'rusak'  => 'Rusak (Afkir / Scrap)',
            default  => ucfirst($this->kondisi ?? 'Baru'),
        };
    }
}
