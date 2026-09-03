<?php

namespace App\Models;

use App\Services\MediaStorageService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSheet extends Model
{
    use HasFactory;

    protected $table = 'data_sheets';

    protected $fillable = [
        'username_pppoe',
        'nama_pelanggan',
        'nama_odp',
        'olt_server',
        'port_odp',
        'foto_rumah_url',
        'foto_odp_url',
        'foto_modem_url',
        'foto_redaman_url',
        'foto_label_kabel_url',
        'foto_ktp_url',
        'foto_dokumen_url',
        'telepon',
        'nik_ktp',
        'mac_address',
        'pon_sn',
        'serial_number',
        'vlan',
        'ip_address',
        'alamat',
        'lokasi_maps',
        'paket',
        'harga_paket',
        'biaya_pasang',
        'tanggal_instalasi',
        'tanggal_jatuh_tempo',
        'status_langganan',
        'status_pembayaran',
        'sales_name',
        'keterangan',
        'raw_data',
        'sheet_row_index',
        'last_synced_at',
    ];

    protected $casts = [
        'raw_data'       => 'array',
        'harga_paket'    => 'decimal:2',
        'biaya_pasang'   => 'decimal:2',
        'last_synced_at' => 'datetime',
    ];

    protected static ?array $cachedSheetMap = null;
    protected static ?array $cachedPrefixMap = null;

    public static function normalizeKey(string $str): string
    {
        $str = str_replace(["\xc2\xa0", "\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"], '', $str);
        $str = strtolower($str);
        $str = preg_replace('/\s+/', '', $str);
        return trim($str);
    }

    public static function generateAliases(string $raw): array
    {
        $clean = self::normalizeKey($raw);
        if ($clean === '') return [];

        $aliases = [$clean => true];

        if (str_contains($clean, '-au-')) {
            $aliases[str_replace('-au-', '-', $clean)] = true;
        } elseif (preg_match('/^(\d{3,5})-(.+)$/', $clean, $m)) {
            $aliases[$m[1] . '-au-' . $m[2]] = true;
        }

        if (str_contains($clean, 'dalem')) {
            $aliases[str_replace('dalem', 'dalam', $clean)] = true;
        } elseif (str_contains($clean, 'dalam')) {
            $aliases[str_replace('dalam', 'dalem', $clean)] = true;
        }

        return array_keys($aliases);
    }

    public static function getSheetMap(): array
    {
        if (static::$cachedSheetMap !== null) {
            return static::$cachedSheetMap;
        }

        $items = static::select('id', 'username_pppoe', 'nama_pelanggan', 'nik_ktp', 'nama_odp', 'port_odp', 'alamat', 'telepon', 'paket', 'status_langganan', 'raw_data')->get();
        $m = [];
        $prefixMap = [];

        foreach ($items as $it) {
            $record = [
                'id'               => $it->id,
                'username_pppoe'   => $it->username_pppoe,
                'nama_pelanggan'   => $it->nama_pelanggan ?: $it->username_pppoe,
                'nik_ktp'          => $it->nik_ktp,
                'nama_odp'         => $it->nama_odp,
                'port_odp'         => $it->port_odp,
                'alamat'           => $it->alamat,
                'telepon'          => $it->telepon,
                'paket'            => $it->paket,
                'status_langganan' => $it->status_langganan,
            ];

            $aliases = self::generateAliases((string)$it->username_pppoe);

            if (is_array($it->raw_data)) {
                if (!empty($it->raw_data[6])) {
                    $aliases = array_merge($aliases, self::generateAliases((string)$it->raw_data[6]));
                }
                if (!empty($it->raw_data[7])) {
                    $aliases = array_merge($aliases, self::generateAliases((string)$it->raw_data[7]));
                }
            }

            foreach (array_unique($aliases) as $alias) {
                if (!isset($m[$alias])) {
                    $m[$alias] = $record;
                }
            }

            $rawLower = strtolower(trim((string)$it->username_pppoe));
            if ($rawLower !== '' && !isset($m[$rawLower])) {
                $m[$rawLower] = $record;
            }

            if (preg_match('/^(\d{3,5})[-_]/', self::normalizeKey((string)$it->username_pppoe), $mPrefix)) {
                if (!isset($prefixMap[$mPrefix[1]])) {
                    $prefixMap[$mPrefix[1]] = $record;
                }
            }
        }

        static::$cachedPrefixMap = $prefixMap;
        return static::$cachedSheetMap = $m;
    }

    public static function findMatchForSecret(string $secretUsername): ?array
    {
        $map = self::getSheetMap();
        $rawLower = strtolower(trim($secretUsername));

        if (isset($map[$rawLower])) {
            return $map[$rawLower];
        }

        $aliases = self::generateAliases($secretUsername);
        foreach ($aliases as $alias) {
            if (isset($map[$alias])) {
                return $map[$alias];
            }
        }

        if (preg_match('/^(\d{3,5})[-_]/', self::normalizeKey($secretUsername), $mPrefix)) {
            if (isset(static::$cachedPrefixMap[$mPrefix[1]])) {
                return static::$cachedPrefixMap[$mPrefix[1]];
            }
        }

        return null;
    }

    public static function clearCache(): void
    {
        static::$cachedSheetMap = null;
        static::$cachedPrefixMap = null;
    }

    public static function resolveGoogleDriveImage(?string $url): string
    {
        return MediaStorageService::resolveUrl($url);
    }

    public function getFotoRumahResolvedAttribute(): string
    {
        return MediaStorageService::resolveUrl($this->foto_rumah_url);
    }

    public function getFotoOdpResolvedAttribute(): string
    {
        return MediaStorageService::resolveUrl($this->foto_odp_url);
    }

    public function getFotoModemResolvedAttribute(): string
    {
        return MediaStorageService::resolveUrl($this->foto_modem_url);
    }

    public function getFotoRedamanResolvedAttribute(): string
    {
        return MediaStorageService::resolveUrl($this->foto_redaman_url);
    }

    public function getFotoKtpResolvedAttribute(): string
    {
        return MediaStorageService::resolveUrl($this->foto_ktp_url);
    }

    public function getFotoLabelKabelResolvedAttribute(): string
    {
        return MediaStorageService::resolveUrl($this->foto_label_kabel_url);
    }

    public function getFotoDokumenResolvedAttribute(): string
    {
        return MediaStorageService::resolveUrl($this->foto_dokumen_url);
    }

    public static function resolveHargaFromPaket(?string $paketName, $currentHarga = null): ?float
    {
        if (is_numeric($currentHarga) && (float)$currentHarga > 1000) {
            return (float)$currentHarga;
        }

        if (empty($paketName) || trim($paketName) === '-' || strcasecmp(trim($paketName), 'gratis') === 0) {
            return (strcasecmp(trim((string)$paketName), 'gratis') === 0) ? 0.0 : null;
        }

        $cleanName = strtolower(trim($paketName));

        $pakets = \Illuminate\Support\Facades\Cache::remember('master_pakets_all_cached_v1', 300, function() {
            return \App\Models\Paket::all();
        });

        foreach ($pakets as $p) {
            if (strcasecmp(trim($p->nama_paket), trim($paketName)) === 0) {
                return (float)$p->tarif_bulanan;
            }
        }

        foreach ($pakets as $p) {
            if (!empty($p->mikrotik_profile) && strcasecmp(trim($p->mikrotik_profile), trim($paketName)) === 0) {
                return (float)$p->tarif_bulanan;
            }
        }

        foreach ($pakets as $p) {
            $pClean = strtolower(trim($p->nama_paket));
            if (str_contains($cleanName, $pClean) || str_contains($pClean, $cleanName)) {
                return (float)$p->tarif_bulanan;
            }
        }

        if (preg_match('/(\d{2,4})\s*k/i', $paketName, $m)) {
            $val = (int)$m[1] * 1000;
            if ($val >= 50000) {
                return (float)$val;
            }
        }

        if (preg_match('/(?:rp\.?|idr)?\s*(\d{1,3}(?:\.\d{3})+|\d{5,8})/i', $paketName, $m)) {
            $val = (float)str_replace(['.', ',', ' '], '', $m[1]);
            if ($val >= 50000) {
                return $val;
            }
        }

        return null;
    }

    public function getHargaPaketResolvedAttribute(): ?float
    {
        return self::resolveHargaFromPaket($this->paket, $this->harga_paket);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'pelanggan_username', 'username_pppoe')->latest('id');
    }
}
