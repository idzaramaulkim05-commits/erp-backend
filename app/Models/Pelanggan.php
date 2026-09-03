<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pelanggan extends Model
{
    protected $table = 'pelanggan';

    public $timestamps = false;

    protected $fillable = [
        'id_customer',
        'nama',
        'kategori_pelanggan',
        'nama_depan',
        'nama_belakang',
        'provinsi',
        'kabupaten',
        'kecamatan',
        'desa',
        'kode_wilayah',
        'username',
        'paket',
        'ip',
        'status',
        'password_pppoe',
        'vlan',
        'mac_address',
        'pon_sn',
        'serial_number',
        'foto_odp',
        'foto_redaman',
        'foto_label_kabel',
        'foto_dokumen',
        'foto_identitas_onu',
        'harga_paket',
        'biaya_pasang',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'harga_paket' => 'decimal:2',
        'biaya_pasang' => 'decimal:2',
    ];
}
