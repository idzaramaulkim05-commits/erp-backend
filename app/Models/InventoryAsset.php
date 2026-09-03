<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InventoryAsset extends Model
{
    use HasFactory;

    protected $table = 'inventory_assets';

    protected $fillable = [
        'warehouse_item_id',
        'kode_barang',
        'nama_barang',
        'kategori',
        'jumlah',
        'satuan',
        'harga_satuan',
        'total_nilai',
        'lokasi_aset',
        'pic_user_id',
        'warehouse_request_id',
        'ticket_id',
        'nomor_referensi',
        'tanggal_pasang',
        'status',
        'catatan',
    ];

    protected $casts = [
        'tanggal_pasang' => 'date',
        'harga_satuan'   => 'decimal:2',
        'total_nilai'    => 'decimal:2',
        'jumlah'         => 'integer',
    ];

    public function item()
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }

    public function pic()
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

    public function warehouseRequest()
    {
        return $this->belongsTo(WarehouseRequest::class, 'warehouse_request_id');
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
