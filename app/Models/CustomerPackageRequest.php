<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerPackageRequest extends Model
{
    use HasFactory;

    protected $table = 'customer_package_requests';

    protected $fillable = [
        'nomor_pengajuan',
        'pelanggan_username',
        'id_customer',
        'pelanggan_nama',
        'paket_lama',
        'paket_baru',
        'harga_lama',
        'harga_baru',
        'alasan',
        'requested_by',
        'status',
        'approved_by',
        'approved_at',
        'catatan_finance',
    ];

    protected $casts = [
        'harga_lama'  => 'decimal:2',
        'harga_baru'  => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
