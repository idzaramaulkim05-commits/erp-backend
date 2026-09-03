<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory;

    protected $table = 'tickets';

    protected $fillable = [
        'ticket_number',
        'type',
        'kategori',
        'prioritas',
        'kategori_pelanggan',
        'pelanggan_nama',
        'nama_depan',
        'nama_belakang',
        'provinsi_kode',
        'kabupaten_kode',
        'kecamatan_kode',
        'desa_kode',
        'id_customer',
        'pelanggan_username',
        'pppoe_password',
        'vlan',
        'request_vlan_at',
        'noc_assigned_vlan_by',
        'noc_assigned_vlan_at',
        'pelanggan_telepon',
        'pelanggan_telepon_alt',
        'nama_marketing',
        'alamat',
        'patokan_alamat',
        'shareloc_url',
        'latitude',
        'longitude',
        'foto_rumah',
        'odp_id',
        'olt_id',
        'paket',
        'paket_layanan',
        'alasan_cabut',
        'kelengkapan_alat',
        'status',
        'deskripsi_keluhan',
        'catatan_cs',
        'catatan_noc',
        'catatan_tl',
        'catatan_teknisi',
        'customer_last_reply',
        'customer_last_reply_at',
        'foto_sebelum',
        'foto_sesudah',
        'foto_odp',
        'foto_redaman',
        'foto_label_kabel',
        'foto_dokumen',
        'redaman_sebelum',
        'redaman_sesudah',
        'serial_number_ont',
        'pon_sn',
        'mac_ont',
        'port_odp',
        'panjang_kabel',
        'harga_paket',
        'biaya_pasang',
        'payment_method',
        'payment_status',
        'catatan_pembayaran',
        'bukti_pembayaran',
        'payment_verified_by',
        'payment_verified_at',
        'created_by',
        'validated_by',
        'validated_at',
        'assigned_by',
        'assigned_to',
        'assigned_technicians',
        'assigned_at',
        'in_progress_at',
        'resolved_at',
        'closed_by',
        'closed_at',
    ];

    protected $casts = [
        'latitude'               => 'float',
        'longitude'              => 'float',
        'panjang_kabel'          => 'integer',
        'harga_paket'            => 'decimal:2',
        'biaya_pasang'           => 'decimal:2',
        'assigned_technicians'   => 'array',
        'validated_at'           => 'datetime',
        'assigned_at'            => 'datetime',
        'in_progress_at'         => 'datetime',
        'resolved_at'            => 'datetime',
        'closed_at'              => 'datetime',
        'request_vlan_at'        => 'datetime',
        'noc_assigned_vlan_at'   => 'datetime',
        'customer_last_reply_at' => 'datetime',
        'payment_verified_at'    => 'datetime',
    ];

    public function isAssignedTo(?User $user): bool
    {
        if (!$user) return false;
        if ($this->assigned_to == $user->id) return true;
        if (!empty($this->assigned_technicians) && is_array($this->assigned_technicians)) {
            return in_array($user->id, $this->assigned_technicians) || in_array((string)$user->id, $this->assigned_technicians);
        }
        return false;
    }

    public static function generateTicketNumber(string $type = 'trouble'): string
    {
        $prefix = match ($type) {
            'psb'               => 'PSB',
            'dismantle'         => 'CBT',
            'relokasi'          => 'RLK',
            'wo', 'maintenance' => 'WO',
            'backbone', 'incident' => 'BB',
            default             => 'TKT',
        };
        $yearMonth = date('Ym');
        
        $lastTicket = self::where('ticket_number', 'like', "{$prefix}-{$yearMonth}-%")
            ->orderBy('id', 'desc')
            ->first();

        if ($lastTicket) {
            $parts = explode('-', $lastTicket->ticket_number);
            $seq = isset($parts[2]) ? (int)$parts[2] + 1 : 1;
        } else {
            $seq = 1;
        }

        return sprintf('%s-%s-%04d', $prefix, $yearMonth, $seq);
    }

    public function odp(): BelongsTo
    {
        return $this->belongsTo(Odp::class, 'odp_id');
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class, 'olt_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function teamLeader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function paymentVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_verified_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(TicketLog::class, 'ticket_id')->orderBy('created_at', 'desc');
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'psb'       => 'Pasang Baru (PSB)',
            'dismantle' => 'Dismantle (Cabut Alat)',
            'relokasi'  => 'Relokasi / Pindah Jalur',
            default     => 'Trouble Ticket',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->type === 'psb') {
            return match ($this->status) {
                'ready_dispatch'    => 'Antrean Disposisi TL',
                'assigned'          => 'Ditugaskan ke Teknisi',
                'in_progress'       => 'Pengerjaan Fisik Lapangan',
                'pending_noc'       => 'Menunggu Alokasi VLAN (NOC)',
                'ready_activation'  => 'Data Lengkap (Siap Aktivasi)',
                'resolved'          => 'Menunggu Validasi & QC (NOC)',
                'closed'            => 'Selesai & Aktif (Closed)',
                'cancelled'         => 'Dibatalkan / Invalid',
                default             => ucfirst(str_replace('_', ' ', $this->status)),
            };
        }

        return match ($this->status) {
            'pending_noc'       => 'Menunggu Validasi NOC',
            'pending_survey'    => 'Menunggu Survei',
            'ready_dispatch'    => 'Siap Disposisi TL',
            'assigned'          => 'Ditugaskan ke Teknisi',
            'in_progress'       => 'Sedang Dikerjakan',
            'pending_sparepart' => 'Kendala Lapangan / Reschedule',
            'pending_gudang'    => 'Menunggu Verifikasi Retur Gudang',
            'resolved'          => 'Kendala Terselesaikan',
            'closed'            => 'Ditutup (Closed)',
            'cancelled'         => 'Dibatalkan (Cancelled)',
            default             => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    public function getFotoRumahResolvedAttribute(): ?string
    {
        return $this->foto_rumah ? \App\Services\MediaStorageService::resolveUrl($this->foto_rumah) : null;
    }

    public function getFotoSebelumResolvedAttribute(): ?string
    {
        return $this->foto_sebelum ? \App\Services\MediaStorageService::resolveUrl($this->foto_sebelum) : null;
    }

    public function getFotoSesudahResolvedAttribute(): ?string
    {
        return $this->foto_sesudah ? \App\Services\MediaStorageService::resolveUrl($this->foto_sesudah) : null;
    }

    public function getFotoOdpResolvedAttribute(): ?string
    {
        return $this->foto_odp ? \App\Services\MediaStorageService::resolveUrl($this->foto_odp) : null;
    }

    public function getFotoRedamanResolvedAttribute(): ?string
    {
        return $this->foto_redaman ? \App\Services\MediaStorageService::resolveUrl($this->foto_redaman) : null;
    }

    public function getFotoLabelKabelResolvedAttribute(): ?string
    {
        return $this->foto_label_kabel ? \App\Services\MediaStorageService::resolveUrl($this->foto_label_kabel) : null;
    }

    public function getFotoDokumenResolvedAttribute(): ?string
    {
        return $this->foto_dokumen ? \App\Services\MediaStorageService::resolveUrl($this->foto_dokumen) : null;
    }

    public function getBuktiPembayaranResolvedAttribute(): ?string
    {
        return $this->bukti_pembayaran ? \App\Services\MediaStorageService::resolveUrl($this->bukti_pembayaran) : null;
    }

    public static function findActiveByPhone(string $phone): ?self
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (empty($cleanPhone)) return null;

        $local = '';
        $intl = '';
        if (str_starts_with($cleanPhone, '62')) {
            $local = '0' . substr($cleanPhone, 2);
        } elseif (str_starts_with($cleanPhone, '0')) {
            $intl = '62' . substr($cleanPhone, 1);
        } else {
            $local = '0' . $cleanPhone;
            $intl = '62' . $cleanPhone;
        }

        return self::whereNotIn('status', ['closed', 'cancelled'])
            ->where(function ($q) use ($cleanPhone, $local, $intl) {
                $q->where('pelanggan_telepon', 'like', "%{$cleanPhone}%")
                  ->orWhere('pelanggan_telepon_alt', 'like', "%{$cleanPhone}%");
                if (!empty($local)) {
                    $q->orWhere('pelanggan_telepon', 'like', "%{$local}%");
                }
                if (!empty($intl)) {
                    $q->orWhere('pelanggan_telepon', 'like', "%{$intl}%");
                }
            })
            ->latest('updated_at')
            ->first();
    }
}
