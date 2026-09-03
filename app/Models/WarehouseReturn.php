<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WarehouseReturn extends Model
{
    use HasFactory;

    protected $table = 'warehouse_returns';

    protected $fillable = [
        'nomor_retur',
        'ticket_id',
        'teknisi_id',
        'pelanggan_nama',
        'warehouse_item_id',
        'nama_barang',
        'serial_number',
        'mac_address',
        'kondisi',
        'foto_barang',
        'status',
        'received_by_gudang_id',
        'received_at',
        'catatan_teknisi',
        'catatan_gudang',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function teknisi()
    {
        return $this->belongsTo(User::class, 'teknisi_id');
    }

    public function warehouseItem()
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }

    public function gudangReceiver()
    {
        return $this->belongsTo(User::class, 'received_by_gudang_id');
    }

    public function getFotoBarangResolvedAttribute(): ?string
    {
        return $this->foto_barang ? \App\Services\MediaStorageService::resolveUrl($this->foto_barang) : null;
    }
}
