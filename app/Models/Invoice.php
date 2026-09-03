<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasFactory;

    protected $table = 'invoices';

    protected $fillable = [
        'nomor_invoice',
        'ticket_id',
        'id_customer',
        'pelanggan_username',
        'pelanggan_nama',
        'kategori_pelanggan',
        'pelanggan_telepon',
        'pelanggan_alamat',
        'marketing_pic',
        'teknisi_pic',
        'paket_nama',
        'harga_paket',
        'biaya_pasang',
        'tax',
        'potongan',
        'total_tagihan',
        'total_dibayar',
        'sisa_piutang',
        'periode_bulan',
        'periode_tahun',
        'tanggal_invoice',
        'tanggal_jatuh_tempo',
        'status',
        'status_isolir',
        'metode_pembayaran',
        'tanggal_bayar',
        'bukti_bayar',
        'keterangan',
        'wa_sent_at',
        'wa_status',
        'created_by',
        'verified_by',
    ];

    protected $casts = [
        'harga_paket'         => 'decimal:2',
        'biaya_pasang'        => 'decimal:2',
        'tax'                 => 'decimal:2',
        'potongan'            => 'decimal:2',
        'total_tagihan'       => 'decimal:2',
        'total_dibayar'       => 'decimal:2',
        'sisa_piutang'        => 'decimal:2',
        'periode_bulan'       => 'integer',
        'periode_tahun'       => 'integer',
        'tanggal_invoice'     => 'date',
        'tanggal_jatuh_tempo' => 'date',
        'tanggal_bayar'       => 'datetime',
        'status_isolir'       => 'boolean',
        'wa_sent_at'          => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function dataSheet(): BelongsTo
    {
        return $this->belongsTo(DataSheet::class, 'pelanggan_username', 'username_pppoe');
    }

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class, 'pelanggan_username', 'username');
    }

    public function getFormattedTotalTagihanAttribute(): string
    {
        return 'Rp ' . number_format((float) $this->total_tagihan, 0, ',', '.');
    }

    public function getFormattedTotalDibayarAttribute(): string
    {
        return 'Rp ' . number_format((float) $this->total_dibayar, 0, ',', '.');
    }

    public function getFormattedSisaPiutangAttribute(): string
    {
        return 'Rp ' . number_format((float) $this->sisa_piutang, 0, ',', '.');
    }

    public function getFormattedHargaPaketAttribute(): string
    {
        return 'Rp ' . number_format((float) $this->harga_paket, 0, ',', '.');
    }

    public function getPeriodeFormattedAttribute(): string
    {
        $bulanList = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $namaBulan = $bulanList[$this->periode_bulan] ?? ('Bulan ' . $this->periode_bulan);
        return "{$namaBulan} {$this->periode_tahun}";
    }

    public function getBuktiBayarResolvedAttribute(): ?string
    {
        return $this->bukti_bayar ? \App\Services\MediaStorageService::resolveUrl($this->bukti_bayar) : null;
    }
}
