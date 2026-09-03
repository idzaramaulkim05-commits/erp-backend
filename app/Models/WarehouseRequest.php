<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WarehouseRequest extends Model
{
    use HasFactory;

    protected $table = 'warehouse_requests';

    protected $fillable = [
        'nomor_request',
        'tipe_request',
        'kategori_kebutuhan',
        'ticket_id',
        'user_id',
        'divisi',
        'alasan',
        'alokasi_aset',
        'target_lokasi',
        'replaced_asset_id',
        'serial_number_lama',
        'lampiran_foto',
        'warehouse_return_id',
        'status',
        'action_pengerjaan',
        'action_done_at',
        'action_by_user_id',
        'linked_action_ticket_id',
        'approved_by_finance_id',
        'approved_by_finance_at',
        'confirmed_by_gudang_id',
        'confirmed_by_gudang_at',
        'catatan_finance',
        'catatan_gudang',
    ];

    protected $casts = [
        'approved_by_finance_at' => 'datetime',
        'confirmed_by_gudang_at' => 'datetime',
        'action_done_at'         => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actionUser()
    {
        return $this->belongsTo(User::class, 'action_by_user_id');
    }

    public function financeApprover()
    {
        return $this->belongsTo(User::class, 'approved_by_finance_id');
    }

    public function gudangConfirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by_gudang_id');
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function linkedActionTicket()
    {
        return $this->belongsTo(Ticket::class, 'linked_action_ticket_id');
    }

    public function replacedAsset()
    {
        return $this->belongsTo(InventoryAsset::class, 'replaced_asset_id');
    }

    public function warehouseReturn()
    {
        return $this->belongsTo(WarehouseReturn::class, 'warehouse_return_id');
    }

    public function items()
    {
        return $this->hasMany(WarehouseRequestItem::class, 'warehouse_request_id');
    }
}
