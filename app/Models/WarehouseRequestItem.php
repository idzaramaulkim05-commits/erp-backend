<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WarehouseRequestItem extends Model
{
    use HasFactory;

    protected $table = 'warehouse_request_items';

    protected $fillable = [
        'warehouse_request_id',
        'warehouse_item_id',
        'jumlah_diminta',
        'jumlah_disetujui',
        'satuan',
        'catatan',
    ];

    public function request()
    {
        return $this->belongsTo(WarehouseRequest::class, 'warehouse_request_id');
    }

    public function item()
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }

    public function warehouseItem()
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }
}
