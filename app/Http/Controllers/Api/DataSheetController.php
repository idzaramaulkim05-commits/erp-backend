<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\DataSheet;
use App\Models\Invoice;
use App\Models\Pelanggan;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\MikrotikService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DataSheetController extends Controller
{
    /**
     * Display Master Data Pelanggan 360° & Data Sheet page.
     */
    public function index(Request $request)
    {
        $setting = Setting::getSetting();

        // High-performance cached stats (60s TTL)
        $stats = \Illuminate\Support\Facades\Cache::remember('datasheet_index_stats_v1', 60, function () {
            return [
                'totalItems'      => DataSheet::count(),
                'totalAktif'      => DataSheet::where('status_langganan', 'aktif')->count(),
                'totalUninstall'  => DataSheet::where(function ($w) {
                    $w->where('status_langganan', 'dismantle')
                      ->orWhere('status_langganan', 'uninstall')
                      ->orWhere('status_langganan', 'unistall');
                })->count(),
                'withFotoRumah'   => DataSheet::whereNotNull('foto_rumah_url')->where('foto_rumah_url', '!=', '')->count(),
                'withFotoOdp'     => DataSheet::whereNotNull('foto_odp_url')->where('foto_odp_url', '!=', '')->count(),
                'withFotoKtp'     => DataSheet::whereNotNull('foto_ktp_url')->where('foto_ktp_url', '!=', '')->count(),
                'withFotoModem'   => DataSheet::whereNotNull('foto_modem_url')->where('foto_modem_url', '!=', '')->count(),
                'allOdps'         => DataSheet::whereNotNull('nama_odp')
                                        ->where('nama_odp', '!=', '-')
                                        ->where('nama_odp', '!=', '')
                                        ->distinct()
                                        ->orderBy('nama_odp', 'asc')
                                        ->pluck('nama_odp')
                                        ->toArray(),
                'allPakets'       => DataSheet::whereNotNull('paket')
                                        ->where('paket', '!=', '')
                                        ->distinct()
                                        ->orderBy('paket', 'asc')
                                        ->pluck('paket')
                                        ->toArray(),
            ];
        });

        $lastSynced = $setting->sheet_last_synced_at;
        $availablePakets = \App\Models\Paket::where('is_active', true)->orderBy('tarif_bulanan')->get();

        return view('datasheet.index', [
            'page'            => 'datasheet',
            'setting'         => $setting,
            'totalItems'      => $stats['totalItems'],
            'totalAktif'      => $stats['totalAktif'],
            'totalUninstall'  => $stats['totalUninstall'],
            'withFotoRumah'   => $stats['withFotoRumah'],
            'withFotoOdp'     => $stats['withFotoOdp'],
            'withFotoKtp'     => $stats['withFotoKtp'],
            'withFotoModem'   => $stats['withFotoModem'],
            'allOdps'         => $stats['allOdps'],
            'allPakets'       => $stats['allPakets'],
            'availablePakets' => $availablePakets,
            'lastSynced'      => $lastSynced,
        ]);
    }

    /**
     * Instant search API for PPPoE username, customer name, ODP, phone, NIK, package or address.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $odp = trim((string) $request->input('odp', ''));
        $tab = trim((string) $request->input('tab', 'all'));
        $paket = trim((string) $request->input('paket', ''));
        $limit = max(1, min(200, (int)$request->input('limit', 100)));

        $cacheKey = 'datasheet_search_v3_' . md5($q . '|' . $odp . '|' . $tab . '|' . $paket . '|' . $limit);

        $payload = \Illuminate\Support\Facades\Cache::remember($cacheKey, 15, function () use ($q, $odp, $tab, $paket, $limit) {
            $query = DataSheet::query();

            // 1. Text Search Across All Fields
            if ($q !== '') {
                $terms = array_filter(explode(' ', $q));
                $query->where(function ($w) use ($terms) {
                    foreach ($terms as $term) {
                        $w->where(function ($sub) use ($term) {
                            $sub->where('username_pppoe', 'like', "%{$term}%")
                                ->orWhere('nama_pelanggan', 'like', "%{$term}%")
                                ->orWhere('nama_odp', 'like', "%{$term}%")
                                ->orWhere('port_odp', 'like', "%{$term}%")
                                ->orWhere('telepon', 'like', "%{$term}%")
                                ->orWhere('nik_ktp', 'like', "%{$term}%")
                                ->orWhere('mac_address', 'like', "%{$term}%")
                                ->orWhere('pon_sn', 'like', "%{$term}%")
                                ->orWhere('serial_number', 'like', "%{$term}%")
                                ->orWhere('ip_address', 'like', "%{$term}%")
                                ->orWhere('paket', 'like', "%{$term}%")
                                ->orWhere('alamat', 'like', "%{$term}%")
                                ->orWhere('tanggal_jatuh_tempo', 'like', "%{$term}%")
                                ->orWhere('keterangan', 'like', "%{$term}%");
                        });
                    }
                });
            }

            // 2. ODP Filter
            if ($odp !== '' && $odp !== 'all') {
                $query->where('nama_odp', $odp);
            }

            // 3. Paket Filter
            if ($paket !== '' && $paket !== 'all') {
                $query->where('paket', $paket);
            }

            // 4. Tab State Filter
            if ($tab === 'aktif') {
                $query->where('status_langganan', 'aktif');
            } elseif ($tab === 'dismantle' || $tab === 'uninstall') {
                $query->where(function ($w) {
                    $w->where('status_langganan', 'dismantle')
                      ->orWhere('status_langganan', 'uninstall')
                      ->orWhere('status_langganan', 'unistall')
                      ->orWhere('status_langganan', 'off')
                      ->orWhere('keterangan', 'like', '%cabut%')
                      ->orWhere('keterangan', 'like', '%dismantle%');
                });
            } elseif ($tab === 'ada_foto_lengkap') {
                $query->whereNotNull('foto_rumah_url')->where('foto_rumah_url', '!=', '')
                      ->whereNotNull('foto_odp_url')->where('foto_odp_url', '!=', '');
            } elseif ($tab === 'ada_foto_rumah') {
                $query->whereNotNull('foto_rumah_url')->where('foto_rumah_url', '!=', '');
            } elseif ($tab === 'ada_foto_odp') {
                $query->whereNotNull('foto_odp_url')->where('foto_odp_url', '!=', '');
            } elseif ($tab === 'ada_foto_ktp') {
                $query->whereNotNull('foto_ktp_url')->where('foto_ktp_url', '!=', '');
            } elseif ($tab === 'jatuh_tempo') {
                $query->whereNotNull('tanggal_jatuh_tempo')->where('tanggal_jatuh_tempo', '!=', '');
            }

            $results = $query->orderBy('nama_odp', 'asc')
                ->orderBy('username_pppoe', 'asc')
                ->limit($limit)
                ->get();

            // 5. Non-blocking PPPoE status attachment from memory cache
            $liveSecrets = [];
            try {
                $secrets = \Illuminate\Support\Facades\Cache::get('mikrotik_persistent_secrets_latest') 
                        ?? \Illuminate\Support\Facades\Cache::get('mikrotik_pppoe_secrets_default')
                        ?? [];
                if (empty($secrets)) {
                    $mikrotik = new MikrotikService();
                    $secrets = $mikrotik->getPppoeSecrets(true);
                }
                foreach ($secrets as $s) {
                    if (!empty($s['username'])) {
                        $liveSecrets[strtolower($s['username'])] = $s;
                    }
                }
            } catch (Throwable $e) {
                // silent fallback
            }

            // 6. Preload ticket counts & invoice counts for fast overview badge
            $usernames = $results->pluck('username_pppoe')->toArray();
            $ticketCounts = Ticket::whereIn('pelanggan_username', $usernames)
                ->select('pelanggan_username', DB::raw('count(*) as total'))
                ->groupBy('pelanggan_username')
                ->pluck('total', 'pelanggan_username')
                ->toArray();

            $invoiceCounts = Invoice::whereIn('pelanggan_username', $usernames)
                ->select('pelanggan_username', DB::raw('count(*) as total'))
                ->groupBy('pelanggan_username')
                ->pluck('total', 'pelanggan_username')
                ->toArray();

            $transformed = $results->map(function (DataSheet $item) use ($liveSecrets, $ticketCounts, $invoiceCounts) {
                $userLower = strtolower($item->username_pppoe);
                $live = $liveSecrets[$userLower] ?? null;

                // Live status & active IP resolution (strictly from active PPPoE connection)
                $isOnline = false;
                $ipActive = '';
                $statusCategory = $item->status_langganan ?: 'aktif';
                if ($live) {
                    $isOnline = (($live['status'] ?? '') === 'Online' || ($live['online'] ?? false) === true);
                    if ($isOnline) {
                        $ipActive = $live['ip_active'] ?? ($live['ip'] ?? ($live['address'] ?? ''));
                        if ($ipActive === '-') $ipActive = '';
                    }
                    $statusCategory = $live['status_category'] ?? ($isOnline ? 'online' : 'offline');
                }

                // Resolve real ID Customer
                $raw = is_array($item->raw_data) ? $item->raw_data : (json_decode($item->raw_data, true) ?: []);
                $idCust = $raw['id_customer'] ?? null;
                if (!empty($idCust) && $idCust === $item->nik_ktp) {
                    $idCust = null;
                }

                // Resolve Maps URL
                $lat = $raw['latitude'] ?? null;
                $lng = $raw['longitude'] ?? null;
                $mapsUrl = $item->lokasi_maps ?: ($raw['shareloc_url'] ?? '');
                if (empty($mapsUrl)) {
                    if (!empty($lat) && !empty($lng)) {
                        $mapsUrl = "https://www.google.com/maps?q={$lat},{$lng}";
                    } elseif (!empty($item->alamat) && preg_match('/(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)/', $item->alamat, $m)) {
                        $lat = (float)$m[1];
                        $lng = (float)$m[2];
                        $mapsUrl = "https://www.google.com/maps?q={$lat},{$lng}";
                    } elseif (!empty($item->keterangan) && preg_match('/(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)/', $item->keterangan, $m)) {
                        $lat = (float)$m[1];
                        $lng = (float)$m[2];
                        $mapsUrl = "https://www.google.com/maps?q={$lat},{$lng}";
                    } elseif (!empty($item->alamat) && $item->alamat !== '-') {
                        $mapsUrl = "https://www.google.com/maps/search/?api=1&query=" . urlencode($item->alamat);
                    } elseif (!empty($item->nama_odp) && $item->nama_odp !== '-') {
                        $mapsUrl = "https://www.google.com/maps/search/?api=1&query=" . urlencode("ODP " . $item->nama_odp);
                    }
                }

                return [
                    'id'                  => $item->id,
                    'id_customer'         => $idCust,
                    'username_pppoe'      => $item->username_pppoe,
                    'nama_pelanggan'      => $item->nama_pelanggan ?: $item->username_pppoe,
                    'nik_ktp'             => $item->nik_ktp ?: '',
                    'nama_odp'            => $item->nama_odp ?: '-',
                    'port_odp'            => $item->port_odp ?: '-',
                    'olt_server'          => $item->olt_server ?: '-',
                    'mac_address'         => $item->mac_address ? trim((string)$item->mac_address) : '',
                    'pon_sn'              => $item->pon_sn ?: '',
                    'serial_number'       => $item->serial_number ?: '',
                    'vlan'                => $item->vlan ?: '',
                    'ip_address'          => $isOnline ? $ipActive : '',
                    'telepon'             => $item->telepon ?: '',
                    'alamat'              => $item->alamat ?: '',
                    'lokasi_maps'         => $item->lokasi_maps ?: '',
                    'maps_url'            => $mapsUrl,
                    'latitude'            => $lat,
                    'longitude'           => $lng,
                    'paket'               => $item->paket ?: '',
                    'harga_paket'         => $item->harga_paket_resolved ? (int)$item->harga_paket_resolved : null,
                    'harga_formatted'     => $item->harga_paket_resolved ? 'Rp ' . number_format($item->harga_paket_resolved, 0, ',', '.') : '',
                    'biaya_pasang'        => $item->biaya_pasang ? (int)$item->biaya_pasang : null,
                    'tanggal_instalasi'   => $item->tanggal_instalasi ?: '',
                    'tanggal_jatuh_tempo' => $item->tanggal_jatuh_tempo ?: '',
                    'status_langganan'    => $item->status_langganan ?: 'aktif',
                    'status_pelanggan_sheet' => strtoupper(trim((string)($raw[12] ?? ($item->status_langganan === 'dismantle' ? 'UNISTALL' : 'DONE INSTAL')))),
                    'status_pembayaran'   => $item->status_pembayaran ?: 'PEMBAYARAN DONE',
                    'sales_name'          => $item->sales_name ?: '',
                    'keterangan'          => $item->keterangan ?: '',
                    'total_tickets'       => $ticketCounts[$item->username_pppoe] ?? 0,
                    'total_invoices'      => $invoiceCounts[$item->username_pppoe] ?? 0,

                    // Photos & Fast Lightbox Thumbnails
                    'foto_rumah_raw'      => $item->foto_rumah_url,
                    'foto_rumah_thumb'    => $item->foto_rumah_resolved,
                    'foto_odp_raw'        => $item->foto_odp_url,
                    'foto_odp_thumb'      => $item->foto_odp_resolved,
                    'foto_modem_raw'      => $item->foto_modem_url,
                    'foto_modem_thumb'    => $item->foto_modem_resolved,
                    'foto_redaman_raw'    => $item->foto_redaman_url,
                    'foto_redaman_thumb'  => $item->foto_redaman_resolved,
                    'foto_ktp_raw'        => $item->foto_ktp_url,
                    'foto_ktp_thumb'      => $item->foto_ktp_resolved,
                    'foto_dokumen_raw'    => $item->foto_dokumen_url,
                    'foto_dokumen_thumb'  => $item->foto_dokumen_resolved,

                    // Live Telemetry
                    'is_online'           => $isOnline,
                    'uptime'              => $live['uptime'] ?? null,
                    'status_category'     => $statusCategory,
                    'last_logged_out'     => $live['last_logged_out'] ?? null,
                    'offline_duration'    => $live['offline_duration'] ?? null,
                ];
            });

            // Filter post-live if requested
            if ($tab === 'online') {
                $transformed = $transformed->filter(fn($c) => $c['is_online'] === true)->values();
            } elseif ($tab === 'offline') {
                $transformed = $transformed->filter(fn($c) => $c['is_online'] === false && $c['status_category'] !== 'disabled')->values();
            }

            return [
                'success' => true,
                'total'   => $transformed->count(),
                'data'    => $transformed->values()->toArray(),
            ];
        });

        return response()->json($payload);
    }

    /**
     * Get 360° Comprehensive Customer Profile with Live Tickets History.
     */
    public function detail(Request $request): JsonResponse
    {
        $id = $request->input('id');
        $username = trim((string)$request->input('username', ''));

        $item = null;
        if (!empty($id)) {
            $item = DataSheet::find($id);
        }
        if (!$item && !empty($username)) {
            $item = DataSheet::where('username_pppoe', $username)->first();
        }

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => '⚠️ Data pelanggan tidak ditemukan.',
            ], 404);
        }

        // Live PPPoE details
        $liveData = null;
        try {
            $mikrotik = new MikrotikService();
            $secrets = $mikrotik->getPppoeSecrets(true);
            foreach ($secrets as $s) {
                if (!empty($s['username']) && strcasecmp($s['username'], $item->username_pppoe) === 0) {
                    $liveData = $s;
                    break;
                }
            }
        } catch (Throwable $e) {}

        // Related Tickets History
        $cleanUser = explode('@', $item->username_pppoe)[0];
        $tickets = Ticket::where('pelanggan_username', $item->username_pppoe)
            ->orWhere('pelanggan_username', $cleanUser)
            ->orWhere('pelanggan_username', 'like', "%{$cleanUser}%")
            ->with(['technician', 'creator', 'odp'])
            ->latest('id')
            ->get();

        $ticketHistory = $tickets->map(function(Ticket $t) {
            return [
                'id'            => $t->id,
                'ticket_number' => $t->ticket_number,
                'type'          => $t->type,
                'type_label'    => $t->type_label,
                'type_badge'    => $t->type_badge_class,
                'status'        => $t->status,
                'status_label'  => $t->status_label,
                'status_badge'  => $t->status_badge_class,
                'kategori'      => $t->kategori_label ?? $t->kategori,
                'prioritas'     => $t->prioritas,
                'technician'    => $t->technician?->nama ?? 'Belum Ada',
                'created_at'    => $t->created_at?->translatedFormat('d M Y, H:i') ?? '',
                'resolved_at'   => $t->resolved_at?->translatedFormat('d M Y, H:i') ?? '',
                'deskripsi'     => $t->deskripsi_keluhan ?: '-',
                'action_taken'  => $t->action_taken ?: '-',
                'url'           => route('ticket.show', $t->id),
            ];
        });

        $rawDetail = is_array($item->raw_data) ? $item->raw_data : (json_decode($item->raw_data, true) ?: []);
        $idCustDetail = $rawDetail['id_customer'] ?? null;
        if (!empty($idCustDetail) && $idCustDetail === $item->nik_ktp) {
            $idCustDetail = null;
        }

        $latDetail = $rawDetail['latitude'] ?? null;
        $lngDetail = $rawDetail['longitude'] ?? null;
        $mapsUrlDetail = $item->lokasi_maps ?: ($rawDetail['shareloc_url'] ?? '');
        if (empty($mapsUrlDetail)) {
            if (!empty($latDetail) && !empty($lngDetail)) {
                $mapsUrlDetail = "https://www.google.com/maps?q={$latDetail},{$lngDetail}";
            } elseif (!empty($item->alamat) && preg_match('/(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)/', $item->alamat, $m)) {
                $latDetail = (float)$m[1];
                $lngDetail = (float)$m[2];
                $mapsUrlDetail = "https://www.google.com/maps?q={$latDetail},{$lngDetail}";
            } elseif (!empty($item->alamat) && $item->alamat !== '-') {
                $mapsUrlDetail = "https://www.google.com/maps/search/?api=1&query=" . urlencode($item->alamat);
            } elseif (!empty($item->nama_odp) && $item->nama_odp !== '-') {
                $mapsUrlDetail = "https://www.google.com/maps/search/?api=1&query=" . urlencode("ODP " . $item->nama_odp);
            }
        }

        $customerData = [
            'id'                  => $item->id,
            'id_customer'         => $idCustDetail,
            'username_pppoe'      => $item->username_pppoe,
            'nama_pelanggan'      => $item->nama_pelanggan ?: $item->username_pppoe,
            'nik_ktp'             => $item->nik_ktp ?: '-',
            'nama_odp'            => $item->nama_odp ?: '-',
            'port_odp'            => $item->port_odp ?: '-',
            'olt_server'          => $item->olt_server ?: '-',
            'telepon'             => $item->telepon ?: '',
            'alamat'              => $item->alamat ?: '',
            'lokasi_maps'         => $item->lokasi_maps ?: '',
            'maps_url'            => $mapsUrlDetail,
            'latitude'            => $latDetail,
            'longitude'           => $lngDetail,
            'paket'               => $item->paket ?: '-',
            'harga_paket'         => $item->harga_paket_resolved ? (int)$item->harga_paket_resolved : null,
            'harga_formatted'     => $item->harga_paket_resolved ? 'Rp ' . number_format($item->harga_paket_resolved, 0, ',', '.') : '-',
            'biaya_pasang'        => $item->biaya_pasang ? (int)$item->biaya_pasang : null,
            'tanggal_instalasi'   => $item->tanggal_instalasi ?: '-',
            'tanggal_jatuh_tempo' => $item->tanggal_jatuh_tempo ?: '-',
            'status_langganan'    => $item->status_langganan ?: 'aktif',
            'status_pembayaran'   => $item->status_pembayaran ?: 'PEMBAYARAN DONE',
            'sales_name'          => $item->sales_name ?: '-',
            'ip_address'          => ($liveData && (($liveData['status'] ?? '') === 'Online' || ($liveData['online'] ?? false) === true)) ? ($liveData['ip_active'] ?? ($liveData['ip'] ?? ($liveData['address'] ?? '-'))) : '-',
            'mac_address'         => $item->mac_address ? trim((string)$item->mac_address) : '-',
            'pon_sn'              => $item->pon_sn ?: '-',
            'serial_number'       => $item->serial_number ?: '-',
            'vlan'                => $item->vlan ?: '-',

            // 5-Angle Photo Gallery (KTP removed as per request)
            'photos' => [
                'rumah'   => ['raw' => $item->foto_rumah_url,   'thumb' => $item->foto_rumah_resolved,   'label' => 'Foto Patokan Rumah'],
                'odp'     => ['raw' => $item->foto_odp_url,     'thumb' => $item->foto_odp_resolved,     'label' => 'Foto ODP & Port'],
                'modem'   => ['raw' => $item->foto_modem_url,   'thumb' => $item->foto_modem_resolved,   'label' => 'Foto Identitas Modem'],
                'redaman' => ['raw' => $item->foto_redaman_url, 'thumb' => $item->foto_redaman_resolved, 'label' => 'Foto Redaman Optik'],
                'dokumen' => ['raw' => $item->foto_dokumen_url, 'thumb' => $item->foto_dokumen_resolved, 'label' => 'Foto Dokumen'],
            ],

            // Live Telemetry
            'live' => $liveData ? [
                'is_online'        => ($liveData['status'] === 'Online'),
                'uptime'           => $liveData['uptime'] ?? '-',
                'status_category'  => $liveData['status_category'] ?? 'online',
                'ip_active'        => $liveData['ip_active'] ?? '-',
                'caller_id'        => $liveData['caller_id'] ?? '-',
                'last_logged_out'  => $liveData['last_logged_out'] ?? '-',
                'offline_duration' => $liveData['offline_duration'] ?? '-',
            ] : null,

            // Ticket History
            'tickets' => $ticketHistory,

            // Invoices History
            'invoices' => Invoice::where('pelanggan_username', $item->username_pppoe)
                ->orderBy('periode_tahun', 'desc')
                ->orderBy('periode_bulan', 'desc')
                ->orderBy('id', 'desc')
                ->get()
                ->map(fn(Invoice $inv) => [
                    'id'                      => $inv->id,
                    'nomor_invoice'           => $inv->nomor_invoice,
                    'periode_formatted'       => $inv->periode_formatted,
                    'paket_nama'              => $inv->paket_nama,
                    'total_tagihan_formatted' => 'Rp ' . number_format((float)$inv->total_tagihan, 0, ',', '.'),
                    'total_dibayar_formatted' => 'Rp ' . number_format((float)$inv->total_dibayar, 0, ',', '.'),
                    'sisa_piutang_formatted'  => 'Rp ' . number_format((float)$inv->sisa_piutang, 0, ',', '.'),
                    'status'                  => $inv->status,
                    'status_badge'            => $inv->status_badge,
                    'metode_pembayaran'       => $inv->metode_pembayaran,
                    'tanggal_bayar_formatted' => $inv->tanggal_bayar ? $inv->tanggal_bayar->format('d/m/Y H:i') : '-',
                    'print_url'               => route('finance.invoice.print', $inv->id),
                ]),
        ];

        return response()->json([
            'success'  => true,
            'customer' => $customerData,
        ]);
    }

    /**
     * Get all published invoices for a specific customer.
     */
    public function customerInvoices(Request $request): JsonResponse
    {
        $username = trim((string) $request->input('username', ''));
        if (!$username) {
            return response()->json([
                'success'  => false,
                'message'  => 'Username PPPoE wajib diisi.',
                'invoices' => [],
            ], 422);
        }

        $invoices = Invoice::where('pelanggan_username', $username)
            ->orderBy('periode_tahun', 'desc')
            ->orderBy('periode_bulan', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $data = $invoices->map(function(Invoice $inv) {
            return [
                'id'                      => $inv->id,
                'nomor_invoice'           => $inv->nomor_invoice,
                'periode_formatted'       => $inv->periode_formatted,
                'paket_nama'              => $inv->paket_nama,
                'total_tagihan'           => (float)$inv->total_tagihan,
                'total_tagihan_formatted' => 'Rp ' . number_format((float)$inv->total_tagihan, 0, ',', '.'),
                'total_dibayar_formatted' => 'Rp ' . number_format((float)$inv->total_dibayar, 0, ',', '.'),
                'sisa_piutang_formatted'  => 'Rp ' . number_format((float)$inv->sisa_piutang, 0, ',', '.'),
                'status'                  => $inv->status,
                'status_badge'            => $inv->status_badge,
                'metode_pembayaran'       => $inv->metode_pembayaran,
                'tanggal_bayar_formatted' => $inv->tanggal_bayar ? $inv->tanggal_bayar->format('d/m/Y H:i') : '-',
                'print_url'               => route('finance.invoice.print', $inv->id),
            ];
        });

        return response()->json([
            'success'  => true,
            'username' => $username,
            'total'    => $data->count(),
            'invoices' => $data,
        ]);
    }

    /**
     * Store new customer or update existing customer manually from web interface.
     */
    public function storeOrUpdate(Request $request): JsonResponse
    {
        $id = $request->input('id');
        $username = trim((string)$request->input('username_pppoe', ''));

        if (empty($username)) {
            return response()->json([
                'success' => false,
                'message' => '⚠️ Username PPPoE wajib diisi.',
            ], 422);
        }

        $statusSheet = strtoupper(trim((string)$request->input('status_pelanggan_sheet', $request->input('status_langganan', 'DONE INSTAL'))));
        if (str_contains($statusSheet, 'UNISTALL') || str_contains($statusSheet, 'UNINSTALL') || str_contains($statusSheet, 'CABUT') || str_contains($statusSheet, 'DISMANTLE')) {
            $statusLangganan = 'dismantle';
            $statusSheet = 'UNISTALL';
        } elseif (str_contains($statusSheet, 'DONE') || str_contains($statusSheet, 'AKTIF') || str_contains($statusSheet, 'SELESAI')) {
            $statusLangganan = 'aktif';
            $statusSheet = 'DONE INSTAL';
        } else {
            $statusLangganan = 'aktif';
        }

        $existingCustomer = !empty($id) ? DataSheet::find($id) : DataSheet::where('username_pppoe', $username)->first();
        $raw = $existingCustomer ? (is_array($existingCustomer->raw_data) ? $existingCustomer->raw_data : (json_decode($existingCustomer->raw_data, true) ?: [])) : [];

        $payload = [
            'username_pppoe'      => $username,
            'nama_pelanggan'      => trim((string)$request->input('nama_pelanggan', $username)),
            'nik_ktp'             => trim((string)$request->input('nik_ktp', '')),
            'telepon'             => trim((string)$request->input('telepon', '')),
            'alamat'              => trim((string)$request->input('alamat', '')),
            'nama_odp'            => trim((string)$request->input('nama_odp', '-')),
            'port_odp'            => trim((string)$request->input('port_odp', '-')),
            'olt_server'          => trim((string)$request->input('olt_server', '-')),
            'paket'               => trim((string)$request->input('paket', '')),
            'harga_paket'         => $request->filled('harga_paket') ? (float)$request->input('harga_paket') : DataSheet::resolveHargaFromPaket($request->input('paket')),
            'biaya_pasang'        => $request->filled('biaya_pasang') ? (float)$request->input('biaya_pasang') : null,
            'tanggal_instalasi'   => trim((string)$request->input('tanggal_instalasi', '')),
            'tanggal_jatuh_tempo' => trim((string)$request->input('tanggal_jatuh_tempo', '')),
            'status_langganan'    => $statusLangganan,
            'status_pembayaran'   => trim((string)$request->input('status_pembayaran', 'PEMBAYARAN DONE')),
            'ip_address'          => trim((string)$request->input('ip_address', '')),
            'mac_address'         => trim((string)$request->input('mac_address', '')),
            'pon_sn'              => trim((string)$request->input('pon_sn', '')),
            'serial_number'       => trim((string)$request->input('serial_number', '')),
            'vlan'                => trim((string)$request->input('vlan', '')),
            'lokasi_maps'         => trim((string)$request->input('lokasi_maps', '')),
            'sales_name'          => trim((string)$request->input('sales_name', '')),
            'keterangan'          => trim((string)$request->input('keterangan', '')),
        ];

        // Update raw_data array for exact Google Sheet layout
        $raw[0] = $username;
        $raw[2] = $payload['nama_pelanggan'];
        $raw[3] = $payload['telepon'];
        $raw[4] = $payload['nik_ktp'];
        $raw[5] = $payload['alamat'];
        $raw[6] = $payload['paket'];
        $raw[7] = $payload['harga_paket'];
        $raw[8] = $payload['nama_odp'];
        $raw[9] = $payload['port_odp'];
        $raw[12] = $statusSheet;
        $raw[13] = $payload['tanggal_instalasi'];
        $raw[14] = $payload['tanggal_jatuh_tempo'];
        $raw[15] = $payload['olt_server'];
        $raw[16] = $payload['mac_address'];
        $raw[17] = $payload['pon_sn'];
        $raw[18] = $payload['lokasi_maps'];
        $payload['raw_data'] = $raw;

        // Handle direct URL photo fields
        // Handle direct URL photo fields (auto download if Google Drive URL)
        if ($request->filled('foto_rumah_url'))   $payload['foto_rumah_url']   = \App\Services\MediaStorageService::downloadAndStoreFromUrl(trim((string)$request->input('foto_rumah_url')), 'datasheet/houses', $username . '_rumah');
        if ($request->filled('foto_odp_url'))     $payload['foto_odp_url']     = \App\Services\MediaStorageService::downloadAndStoreFromUrl(trim((string)$request->input('foto_odp_url')), 'datasheet/odp', $username . '_odp');
        if ($request->filled('foto_modem_url'))   $payload['foto_modem_url']   = \App\Services\MediaStorageService::downloadAndStoreFromUrl(trim((string)$request->input('foto_modem_url')), 'datasheet/modem', $username . '_modem');
        if ($request->filled('foto_redaman_url')) $payload['foto_redaman_url'] = \App\Services\MediaStorageService::downloadAndStoreFromUrl(trim((string)$request->input('foto_redaman_url')), 'datasheet/redaman', $username . '_redaman');
        if ($request->filled('foto_ktp_url'))     $payload['foto_ktp_url']     = \App\Services\MediaStorageService::downloadAndStoreFromUrl(trim((string)$request->input('foto_ktp_url')), 'datasheet/ktp', $username . '_ktp');
        if ($request->filled('foto_dokumen_url')) $payload['foto_dokumen_url'] = \App\Services\MediaStorageService::downloadAndStoreFromUrl(trim((string)$request->input('foto_dokumen_url')), 'datasheet/documents', $username . '_dokumen');

        // Handle uploaded photo files if present
        if ($request->hasFile('foto_rumah_file')) {
            $payload['foto_rumah_url'] = \App\Services\MediaStorageService::storeUploadedFile($request->file('foto_rumah_file'), 'datasheet/houses', $username . '_rumah');
        }
        if ($request->hasFile('foto_odp_file')) {
            $payload['foto_odp_url'] = \App\Services\MediaStorageService::storeUploadedFile($request->file('foto_odp_file'), 'datasheet/odp', $username . '_odp');
        }
        if ($request->hasFile('foto_modem_file')) {
            $payload['foto_modem_url'] = \App\Services\MediaStorageService::storeUploadedFile($request->file('foto_modem_file'), 'datasheet/modem', $username . '_modem');
        }
        if ($request->hasFile('foto_redaman_file')) {
            $payload['foto_redaman_url'] = \App\Services\MediaStorageService::storeUploadedFile($request->file('foto_redaman_file'), 'datasheet/redaman', $username . '_redaman');
        }
        if ($request->hasFile('foto_ktp_file')) {
            $payload['foto_ktp_url'] = \App\Services\MediaStorageService::storeUploadedFile($request->file('foto_ktp_file'), 'datasheet/ktp', $username . '_ktp');
        }
        if ($request->hasFile('foto_dokumen_file')) {
            $payload['foto_dokumen_url'] = \App\Services\MediaStorageService::storeUploadedFile($request->file('foto_dokumen_file'), 'datasheet/documents', $username . '_dokumen');
        }

        if (!empty($id)) {
            $customer = DataSheet::find($id);
            if ($customer) {
                $customer->update($payload);

                // Update Pelanggan table if exists
                Pelanggan::where('username', $username)->update([
                    'nama'        => $payload['nama_pelanggan'],
                    'telepon'     => $payload['telepon'],
                    'alamat'      => $payload['alamat'],
                    'paket'       => $payload['paket'],
                    'harga_paket' => $payload['harga_paket'],
                    'status'      => $statusSheet,
                ]);

                // Sync to Google Sheet and Drive webhook (Async, Anti Delay!)
                \App\Services\GoogleSheetSyncService::syncDataSheetToGoogleSheetAsync($customer);
                return response()->json([
                    'success'  => true,
                    'message'  => "✅ Data pelanggan '{$payload['nama_pelanggan']}' berhasil diperbarui & disinkronkan ke Google Sheet!",
                    'customer' => $customer,
                ]);
            }
        }

        $customer = DataSheet::updateOrCreate(
            ['username_pppoe' => $username],
            $payload
        );

        // Update Pelanggan table if exists
        Pelanggan::where('username', $username)->update([
            'nama'        => $payload['nama_pelanggan'],
            'telepon'     => $payload['telepon'],
            'alamat'      => $payload['alamat'],
            'paket'       => $payload['paket'],
            'harga_paket' => $payload['harga_paket'],
            'status'      => $statusSheet,
        ]);

        // Sync to Google Sheet and Drive webhook (Async, Anti Delay!)
        \App\Services\GoogleSheetSyncService::syncDataSheetToGoogleSheetAsync($customer);

        return response()->json([
            'success'  => true,
            'message'  => "✅ Data pelanggan '{$payload['nama_pelanggan']}' berhasil disimpan & disinkronkan ke Google Sheet!",
            'customer' => $customer,
        ]);
    }

    /**
     * Sync data from Google Sheet URL.
     */
    public function sync(Request $request): JsonResponse
    {
        $setting = Setting::getSetting();
        $sheetUrl = trim((string) $request->input('sheet_url', $setting->google_sheet_url ?? ''));

        if (empty($sheetUrl)) {
            return response()->json([
                'success' => false,
                'message' => '⚠️ URL Google Sheet belum diisi. Silakan masukkan link Google Sheet terlebih dahulu.',
            ], 422);
        }

        // Convert standard Google Sheet URL to direct CSV export link
        $csvUrl = $this->convertToCsvExportUrl($sheetUrl);

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                ])
                ->get($csvUrl);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => '🔴 Gagal mengunduh data dari Google Sheet (HTTP ' . $response->status() . '). Pastikan akses Google Sheet disetel ke "Siapa saja yang memiliki link (Anyone with the link can view)"!',
                ], 400);
            }

            $csvContent = $response->body();
            $result = $this->processCsvContent($csvContent);

            if ($result['count'] === 0) {
                return response()->json([
                    'success' => false,
                    'message' => '⚠️ Tidak ada baris data yang ditemukan pada file Sheet.',
                ], 400);
            }

            // Save sheet URL, webhook URL, and last synced timestamp
            $setting->google_sheet_url = $sheetUrl;
            if ($request->filled('webhook_url')) {
                $setting->google_sheet_webhook_url = trim((string) $request->input('webhook_url'));
            }
            $setting->sheet_last_synced_at = now();
            $setting->save();

            return response()->json([
                'success'        => true,
                'message'        => "✅ Berhasil mensinkronkan {$result['count']} data pelanggan dari Google Sheet!",
                'count'          => $result['count'],
                'last_synced_at' => now()->translatedFormat('d M Y, H:i:s'),
            ]);
        } catch (Throwable $e) {
            Log::error("DataSheet Sync Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '🔴 Terjadi kendala saat memproses Google Sheet: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload and parse local CSV file.
     */
    public function uploadCsv(Request $request): JsonResponse
    {
        $request->validate([
            'csv_file' => 'required|file|max:51200', // max 50MB
        ]);

        $file = $request->file('csv_file');
        $csvContent = file_get_contents($file->getRealPath());

        $result = $this->processCsvContent($csvContent);

        if ($result['count'] === 0) {
            return response()->json([
                'success' => false,
                'message' => '⚠️ Tidak ada data yang berhasil diimpor. Pastikan file CSV berisi baris data pelanggan.',
            ], 400);
        }

        $setting = Setting::getSetting();
        $setting->sheet_last_synced_at = now();
        $setting->save();

        return response()->json([
            'success'        => true,
            'message'        => "✅ Berhasil mengimpor {$result['count']} data pelanggan dari file CSV!",
            'count'          => $result['count'],
            'last_synced_at' => now()->translatedFormat('d M Y, H:i:s'),
        ]);
    }

    /**
     * Convert various Google Sheet URLs into direct CSV export link.
     */
    protected function convertToCsvExportUrl(string $url): string
    {
        $url = trim($url);

        // If already CSV export format
        if (str_contains($url, 'export?format=csv') || str_contains($url, 'output=csv')) {
            return $url;
        }

        // Pattern 1: https://docs.google.com/spreadsheets/d/{SPREADSHEET_ID}/edit#gid={GID}
        if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/i', $url, $m)) {
            $sheetId = $m[1];
            $gid = '0';
            if (preg_match('/[#&?]gid=([0-9]+)/i', $url, $mGid)) {
                $gid = $mGid[1];
            }
            return "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&gid={$gid}";
        }

        // Pattern 2: Published web link https://docs.google.com/spreadsheets/d/e/{ID}/pubhtml
        if (preg_match('/\/spreadsheets\/d\/e\/([a-zA-Z0-9-_]+)/i', $url, $m)) {
            return "https://docs.google.com/spreadsheets/d/e/{$m[1]}/pub?output=csv";
        }

        return $url;
    }

    /**
     * Parse and import CSV content into data_sheets database (Blazing Fast & Robust 360° Parser).
     */
    protected function processCsvContent(string $csvContent): array
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        // Strip UTF-8 BOM
        $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent);
        if (trim($csvContent) === '') {
            return ['count' => 0];
        }

        // Auto-detect delimiter (, or ; or \t or |)
        $delimiter = $this->detectDelimiter($csvContent);

        // Open stream
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csvContent);
        rewind($handle);

        // Parse header row
        $headerRow = fgetcsv($handle, 0, $delimiter);
        if (!$headerRow || empty($headerRow)) {
            fclose($handle);
            return ['count' => 0];
        }

        $colMap = [];
        foreach ($headerRow as $idx => $headerText) {
            $norm = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$headerText));
            if ($norm === '') continue;

            if ($norm === 'timestamp' || $norm === 'waktu') {
                $colMap['timestamp'] = $idx;
            } elseif ($norm === 'kategoripelanggan' || $norm === 'kategori') {
                $colMap['kategori_pelanggan'] = $idx;
            } elseif (in_array($norm, ['namapelanggan', 'nama', 'customer', 'namacustomer', 'client'], true)) {
                $colMap['nama_pelanggan'] = $idx;
            } elseif (in_array($norm, ['nohp', 'telepon', 'hp', 'wa', 'nowa', 'whatsapp', 'telp', 'nomorhp', 'phone'], true)) {
                $colMap['telepon'] = $idx;
            } elseif (in_array($norm, ['nikpelanggan', 'nikktp', 'nik', 'noktp', 'ktp', 'nomorktp', 'idcustomer', 'idcust'], true)) {
                $colMap['nik_ktp'] = $idx;
            } elseif (in_array($norm, ['alamat', 'lokasi', 'address', 'patokan', 'wilayah', 'dusun', 'desa'], true)) {
                $colMap['alamat'] = $idx;
            } elseif (in_array($norm, ['pembaruanpppoe', 'pembaruanuser', 'pembaruanusername', 'updatepppoe'], true)) {
                $colMap['pembaruan_pppoe'] = $idx;
            } elseif (in_array($norm, ['pppoe', 'usernamepppoe', 'username', 'userpppoe', 'akunpppoe'], true)) {
                $colMap['username_pppoe'] = $idx;
            } elseif (in_array($norm, ['pembayaranjatuhtempo', 'tgljatuhtempo', 'jatuhtempo', 'tanggaltagihan', 'billingcycle'], true)) {
                $colMap['tanggal_jatuh_tempo'] = $idx;
            } elseif (in_array($norm, ['paket', 'paketlayanan', 'profile', 'kecepatan', 'layanan'], true)) {
                $colMap['paket'] = $idx;
            } elseif (in_array($norm, ['marketing', 'sales', 'salesname', 'namamarketing'], true)) {
                $colMap['sales_name'] = $idx;
            } elseif (in_array($norm, ['mms', 'metodepembayaran', 'metodebayar', 'pembayaran'], true)) {
                $colMap['metode_bayar'] = $idx;
            } elseif (in_array($norm, ['statuspelanggan', 'status', 'statuslangganan'], true)) {
                $colMap['status_pelanggan'] = $idx;
            } elseif (in_array($norm, ['pembayaraninstalasipsbviatf', 'pembayaranpsbviatf', 'pembayaranviatf', 'pembayarantf', 'instalasipsbviatf', 'psbviatf'], true)) {
                $colMap['pembayaran_tf'] = $idx;
            } elseif (in_array($norm, ['pembayaraninstalasipsb', 'pembayaraninstalasi', 'pembayaranpsb', 'statusbayar', 'statuspembayaran'], true)) {
                $colMap['status_pembayaran'] = $idx;
            } elseif (in_array($norm, ['ip', 'ipaddress', 'ipont', 'ipmodem'], true)) {
                $colMap['ip_address'] = $idx;
            } elseif (in_array($norm, ['totaltagihanawal', 'tagihanawal', 'hargapaket', 'tarif', 'totaltagihan', 'nominaltagihan'], true)) {
                $colMap['harga_paket'] = $idx;
            } elseif (in_array($norm, ['biayapasang', 'biayapemasangan', 'biayaregistrasi', 'ongkospasang'], true)) {
                $colMap['biaya_pasang'] = $idx;
            } elseif (in_array($norm, ['nomac', 'macaddress', 'macont', 'maconu', 'macmodem', 'mac'], true)) {
                $colMap['mac_address'] = $idx;
            } elseif (in_array($norm, ['noponsn', 'nopon', 'gponsn', 'ponsn', 'snont', 'snonu', 'sngpon'], true)) {
                $colMap['pon_sn'] = $idx;
            } elseif (in_array($norm, ['nosn', 'serialnumber', 'sn', 'snmodem'], true)) {
                $colMap['serial_number'] = $idx;
            } elseif (in_array($norm, ['keteranganalat', 'keterangan', 'catatan', 'note', 'kelengkapanalat', 'kelengkapan'], true)) {
                $colMap['keterangan'] = $idx;
            } elseif (in_array($norm, ['fotodokumen', 'fotoba', 'fotofilesurat', 'dokumen', 'beritaacara', 'suratperjanjian'], true)) {
                $colMap['foto_dokumen_url'] = $idx;
            } elseif (in_array($norm, ['fotoidentitasmodemonu', 'fotoidentitasmodem', 'fotoidentitasonu', 'fotomodem', 'fotomodemonu', 'fotoonu', 'fotoidentitas'], true)) {
                $colMap['foto_modem_url'] = $idx;
            } elseif (in_array($norm, ['fotoodp', 'fotosambunganodp', 'fotoportodp'], true)) {
                $colMap['foto_odp_url'] = $idx;
            } elseif (in_array($norm, ['fotolabelname', 'fotolabelkabel', 'fotolabelkabeldiodp', 'fotolabel', 'labelname', 'labelkabel'], true)) {
                $colMap['foto_label_kabel_url'] = $idx;
            } elseif (in_array($norm, ['fotoredaman', 'fotoredamanont', 'fotoredamanodp', 'redaman'], true)) {
                $colMap['foto_redaman_url'] = $idx;
            } elseif (in_array($norm, ['fotodepanrumah', 'fotorumah', 'fototampakdepan', 'depanrumah', 'rumah'], true)) {
                $colMap['foto_rumah_url'] = $idx;
            } elseif (in_array($norm, ['fotoktp', 'ktpfoto', 'linkktp'], true)) {
                $colMap['foto_ktp_url'] = $idx;
            } elseif (in_array($norm, ['tanggalpemasangan', 'tanggalinstalasi', 'tanggalpasang', 'tglpasang', 'tglinstalasi', 'tanggalregistrasi'], true)) {
                $colMap['tanggal_instalasi'] = $idx;
            } elseif (in_array($norm, ['merkmodem', 'merkonu', 'tipeonu', 'tipemodem', 'oltserver', 'olt'], true)) {
                $colMap['olt_server'] = $idx;
            } elseif (in_array($norm, ['latitude', 'lat', 'titikmaps', 'shareloc', 'lokasimaps', 'gmaps', 'maps'], true)) {
                $colMap['lokasi_maps'] = $idx;
            } elseif (in_array($norm, ['odp', 'namaodp', 'kodeodp', 'idodp'], true)) {
                $colMap['nama_odp'] = $idx;
            } elseif (in_array($norm, ['port', 'portodp', 'noport', 'slot'], true)) {
                $colMap['port_odp'] = $idx;
            }
        }

        $importedCount = 0;
        $processedIds = [];
        $usedUsernamesInThisBatch = [];
        $existingMap = DataSheet::pluck('id', 'username_pppoe')->toArray();
        $rowIndex = 0;

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowIndex++;
                if (empty($row) || (count($row) === 1 && trim($row[0] ?? '') === '')) {
                    continue;
                }

                // 1. Determine PPPoE Username
                // Priority 1: Pembaruan PPPoE
                $pembaruanPppoe = isset($colMap['pembaruan_pppoe']) ? trim((string)($row[$colMap['pembaruan_pppoe']] ?? '')) : '';
                // Priority 2: Standard PPPoE
                $pppoeRaw = isset($colMap['username_pppoe']) ? trim((string)($row[$colMap['username_pppoe']] ?? '')) : '';

                $username = '';
                if (!empty($pembaruanPppoe) && $pembaruanPppoe !== '-' && strcasecmp($pembaruanPppoe, 'pembaruan pppoe') !== 0) {
                    $username = $pembaruanPppoe;
                } elseif (!empty($pppoeRaw) && $pppoeRaw !== '-' && strcasecmp($pppoeRaw, 'pppoe') !== 0) {
                    $username = $pppoeRaw;
                }

                // If empty or header, search in all cells for PPPoE pattern e.g. user@babatan or 2135-xxx@xxx
                if ($username === '' || strcasecmp($username, 'username') === 0 || strcasecmp($username, 'pppoe') === 0) {
                    foreach ($row as $cVal) {
                        $cTrim = trim((string)$cVal);
                        if (str_contains($cTrim, '@') && !str_contains($cTrim, 'http') && !str_contains($cTrim, ' ') && strlen($cTrim) > 4) {
                            $username = $cTrim;
                            break;
                        }
                    }
                }

                // 2. Extract Photos dynamically based on resolved header positions
                $fotoRumah      = isset($colMap['foto_rumah_url']) ? trim((string)($row[$colMap['foto_rumah_url']] ?? '')) : '';
                $fotoModem      = isset($colMap['foto_modem_url']) ? trim((string)($row[$colMap['foto_modem_url']] ?? '')) : '';
                $fotoOdp        = isset($colMap['foto_odp_url']) ? trim((string)($row[$colMap['foto_odp_url']] ?? '')) : '';
                $fotoLabelKabel = isset($colMap['foto_label_kabel_url']) ? trim((string)($row[$colMap['foto_label_kabel_url']] ?? '')) : '';
                $fotoRedaman    = isset($colMap['foto_redaman_url']) ? trim((string)($row[$colMap['foto_redaman_url']] ?? '')) : '';
                $fotoDokumen    = isset($colMap['foto_dokumen_url']) ? trim((string)($row[$colMap['foto_dokumen_url']] ?? '')) : '';
                $fotoKtp        = isset($colMap['foto_ktp_url']) ? trim((string)($row[$colMap['foto_ktp_url']] ?? '')) : '';

                $telepon        = isset($colMap['telepon']) ? trim((string)($row[$colMap['telepon']] ?? '')) : '';
                $nikKtp         = isset($colMap['nik_ktp']) ? trim((string)($row[$colMap['nik_ktp']] ?? '')) : '';
                $macAddress     = isset($colMap['mac_address']) ? trim((string)($row[$colMap['mac_address']] ?? '')) : '';
                $ponSn          = isset($colMap['pon_sn']) ? trim((string)($row[$colMap['pon_sn']] ?? '')) : '';
                $serialNumber   = isset($colMap['serial_number']) ? trim((string)($row[$colMap['serial_number']] ?? '')) : '';
                $ipAddress      = isset($colMap['ip_address']) ? trim((string)($row[$colMap['ip_address']] ?? '')) : '';
                $oltServer      = isset($colMap['olt_server']) ? trim((string)($row[$colMap['olt_server']] ?? '')) : '';
                $salesName      = isset($colMap['sales_name']) ? trim((string)($row[$colMap['sales_name']] ?? '')) : 'EONET';
                $tglPasang      = isset($colMap['tanggal_instalasi']) ? trim((string)($row[$colMap['tanggal_instalasi']] ?? '')) : (isset($colMap['timestamp']) ? trim((string)($row[$colMap['timestamp']] ?? '')) : '');
                $tglJatuhTempo  = isset($colMap['tanggal_jatuh_tempo']) ? trim((string)($row[$colMap['tanggal_jatuh_tempo']] ?? '')) : '';

                // Payment Status: Check Cash & Transfer Columns
                $statusBayarCash = isset($colMap['status_pembayaran']) ? trim((string)($row[$colMap['status_pembayaran']] ?? '')) : '';
                $statusBayarTf   = isset($colMap['pembayaran_tf']) ? trim((string)($row[$colMap['pembayaran_tf']] ?? '')) : '';
                $statusBayar = (!empty($statusBayarTf) && $statusBayarTf !== '-') ? $statusBayarTf : ($statusBayarCash ?: 'PEMBAYARAN DONE');

                $hargaPaketRaw  = isset($colMap['harga_paket']) ? trim((string)($row[$colMap['harga_paket']] ?? '')) : '';
                $hargaPaket = preg_replace('/[^0-9]/', '', $hargaPaketRaw);
                $biayaPasangRaw = isset($colMap['biaya_pasang']) ? trim((string)($row[$colMap['biaya_pasang']] ?? '')) : '';
                $biayaPasang = preg_replace('/[^0-9]/', '', $biayaPasangRaw);

                // Fallback phone extraction if empty
                if (empty($telepon)) {
                    foreach ($row as $cVal) {
                        $cTrim = trim((string)$cVal);
                        if (preg_match('/^(\+?62|08)\d{7,14}$/', preg_replace('/[\s-]/', '', $cTrim))) {
                            $telepon = $cTrim;
                            break;
                        }
                    }
                }

                // If still no username, generate fallback key
                if ($username === '' || $username === '-') {
                    if (!empty($nikKtp) && $nikKtp !== '-') {
                        $username = "user_{$nikKtp}";
                    } elseif (!empty($telepon)) {
                        $cleanTel = preg_replace('/[^0-9]/', '', $telepon);
                        $username = "user_{$cleanTel}";
                    } elseif (!empty($fotoRumah) || !empty($fotoOdp)) {
                        $username = "pelanggan_row_{$rowIndex}";
                    } else {
                        continue;
                    }
                }

                // If this exact username was already claimed by another row in the same sheet, differentiate it
                if (isset($usedUsernamesInThisBatch[$username])) {
                    $username = "{$username}_row{$rowIndex}";
                }
                $usedUsernamesInThisBatch[$username] = true;

                $alamat     = isset($colMap['alamat']) ? trim((string)($row[$colMap['alamat']] ?? '')) : '';
                $lokasiMaps = isset($colMap['lokasi_maps']) ? trim((string)($row[$colMap['lokasi_maps']] ?? '')) : '';
                $paket      = isset($colMap['paket']) ? trim((string)($row[$colMap['paket']] ?? '')) : '';
                $keterangan = isset($colMap['keterangan']) ? trim((string)($row[$colMap['keterangan']] ?? '')) : '';
                $portOdp    = isset($colMap['port_odp']) ? trim((string)($row[$colMap['port_odp']] ?? '')) : '-';
                $namaOdp    = isset($colMap['nama_odp']) ? trim((string)($row[$colMap['nama_odp']] ?? '')) : '-';
                $nama       = isset($colMap['nama_pelanggan']) ? trim((string)($row[$colMap['nama_pelanggan']] ?? '')) : '';

                if ($nama === '' || $nama === $username) {
                    if (preg_match('/^(?:\d+[-_])?([^@]+)(?:@(.+))?$/', $username, $mUser)) {
                        $nama = ucwords(str_replace(['.', '_', '-'], ' ', $mUser[1]));
                    } else {
                        $nama = $username;
                    }
                }

                $payload = [
                    'username_pppoe'      => $username,
                    'nama_pelanggan'      => $nama ?: $username,
                    'nik_ktp'             => (!empty($nikKtp) && $nikKtp !== '-') ? $nikKtp : null,
                    'nama_odp'            => $namaOdp ?: '-',
                    'port_odp'            => $portOdp ?: '-',
                    'olt_server'          => $oltServer ?: '-',
                    'sales_name'          => $salesName ?: 'EONET',
                    'foto_rumah_url'      => $fotoRumah,
                    'foto_odp_url'        => $fotoOdp,
                    'foto_modem_url'      => $fotoModem,
                    'foto_redaman_url'    => $fotoRedaman,
                    'foto_ktp_url'        => $fotoKtp,
                    'foto_dokumen_url'    => $fotoDokumen,
                    'telepon'             => $telepon,
                    'mac_address'         => $macAddress,
                    'pon_sn'              => $ponSn,
                    'serial_number'       => $serialNumber,
                    'ip_address'          => $ipAddress,
                    'alamat'              => $alamat,
                    'lokasi_maps'         => $lokasiMaps,
                    'paket'               => $paket,
                    'harga_paket'         => (is_numeric($hargaPaket) && (int)$hargaPaket > 1000) ? (int)$hargaPaket : DataSheet::resolveHargaFromPaket($paket),
                    'biaya_pasang'        => is_numeric($biayaPasang) ? (int)$biayaPasang : null,
                    'tanggal_instalasi'   => $tglPasang,
                    'tanggal_jatuh_tempo' => $tglJatuhTempo,
                    'status_pembayaran'   => $statusBayar ?: 'PEMBAYARAN DONE',
                    'status_langganan'    => (function() use ($row) {
                        $rawStatus = strtoupper(trim((string)($row[12] ?? ($row['status'] ?? ''))));
                        if (str_contains($rawStatus, 'UNISTALL') || str_contains($rawStatus, 'UNINSTALL') || str_contains($rawStatus, 'CABUT') || str_contains($rawStatus, 'DISMANTLE')) {
                            return 'dismantle';
                        }
                        return 'aktif';
                    })(),
                    'keterangan'          => $keterangan,
                    'raw_data'            => $row,
                ];

                $matchedId = $existingMap[$username] ?? null;

                if ($matchedId) {
                    DataSheet::where('id', $matchedId)->update($payload);
                    $processedIds[] = $matchedId;
                } else {
                    $newModel = DataSheet::create($payload);
                    $existingMap[$username] = $newModel->id;
                    $processedIds[] = $newModel->id;
                }

                $importedCount++;
            }

            // Clean orphaned legacy duplicates
            if (count($processedIds) > 0) {
                DataSheet::whereNotIn('id', $processedIds)->delete();
            }

            DB::commit();
            fclose($handle);
            return ['count' => $importedCount];
        } catch (Throwable $e) {
            DB::rollBack();
            fclose($handle);
            Log::error("CSV import error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Detect CSV delimiter accurately (, or ; or \t or |).
     */
    protected function detectDelimiter(string $csvContent): string
    {
        $firstLines = substr($csvContent, 0, 4096);
        $delimiters = [',', ';', "\t", '|'];
        $counts = [];

        foreach ($delimiters as $delim) {
            $counts[$delim] = substr_count($firstLines, $delim);
        }

        arsort($counts);
        return array_key_first($counts) ?: ',';
    }

    /**
     * Exact or prefix lookup by PPPoE username for auto-filling tickets.
     */
    public function lookup(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('username', $request->input('q', '')));
        if ($q === '') {
            return response()->json(['found' => false]);
        }

        // 1. Search in local DataSheet table
        $item = DataSheet::where('username_pppoe', $q)->first();

        if (!$item && str_contains($q, '@')) {
            $prefix = explode('@', $q)[0];
            $item = DataSheet::where('username_pppoe', $prefix)
                ->orWhere('username_pppoe', 'like', "%{$prefix}%")
                ->orWhere('nama_pelanggan', 'like', "%{$prefix}%")
                ->first();
        }

        if (!$item) {
            $cleanUser = preg_replace('/^user_/', '', $q);
            $cleanUser = explode('@', $cleanUser)[0];
            $item = DataSheet::where('username_pppoe', 'like', "%{$cleanUser}%")
                ->orWhere('nama_pelanggan', 'like', "%{$cleanUser}%")
                ->orWhere('nik_ktp', 'like', "%{$cleanUser}%")
                ->orWhere('telepon', 'like', "%{$cleanUser}%")
                ->first();
        }

        // 2. Also search in local Pelanggan table
        $cleanPrefix = explode('@', preg_replace('/^user_/', '', $q))[0];
        $pelanggan = Pelanggan::where('username', $q)
            ->orWhere('username', 'like', "%{$cleanPrefix}%")
            ->orWhere('nama', 'like', "%{$cleanPrefix}%")
            ->orWhere('id_customer', 'like', "%{$cleanPrefix}%")
            ->first();

        // Check if there is an active dismantle ticket
        $activeTicket = Ticket::where(function($query) use ($q, $cleanPrefix, $item, $pelanggan) {
            $query->where('pelanggan_username', $q)
                  ->orWhere('pelanggan_username', 'like', "%{$cleanPrefix}%");
            if ($item && !empty($item->username_pppoe)) {
                $query->orWhere('pelanggan_username', $item->username_pppoe);
            }
            if ($pelanggan && !empty($pelanggan->username)) {
                $query->orWhere('pelanggan_username', $pelanggan->username);
            }
        })
        ->where('type', 'dismantle')
        ->whereNotIn('status', ['closed', 'cancelled'])
        ->with('technician')
        ->latest()
        ->first();

        $activeTicketInfo = null;
        if ($activeTicket) {
            $activeTicketInfo = [
                'id'            => $activeTicket->id,
                'ticket_number' => $activeTicket->ticket_number,
                'status'        => $activeTicket->status,
                'status_label'  => $activeTicket->status_label,
                'status_badge'  => $activeTicket->status_badge_class,
                'created_at'    => $activeTicket->created_at?->translatedFormat('d M Y, H:i') ?? '',
                'technician'    => $activeTicket->technician?->nama ?? 'Belum Ditugaskan (Menunggu TL)',
                'url'           => route('ticket.show', $activeTicket->id),
            ];
        }

        if (!$item && !$pelanggan) {
            return response()->json([
                'found'                  => false,
                'active_dismantle_ticket'=> $activeTicketInfo,
            ]);
        }

        $rawData = ($item && is_array($item->raw_data)) ? $item->raw_data : [];

        // Parse phone
        $mainPhone = $item?->telepon ?: ($rawData['telepon'] ?? ($rawData['no_hp'] ?? ($rawData['no_whatsapp'] ?? '')));
        $altPhone  = $rawData['telepon_alt'] ?? ($rawData['no_hp_2'] ?? ($rawData['no_cadangan'] ?? ''));

        if (empty($altPhone) && (str_contains($mainPhone, '/') || str_contains($mainPhone, ','))) {
            $parts = preg_split('/[\/,]/', $mainPhone);
            $mainPhone = trim($parts[0]);
            $altPhone  = trim($parts[1] ?? '');
        }

        $mapsUrl = $item?->lokasi_maps ?: ($rawData['lokasi_maps'] ?? ($rawData['maps'] ?? ($rawData['shareloc'] ?? '')));
        if ($mapsUrl && preg_match('/^-?\d+(\.\d+)?\s*,\s*-?\d+(\.\d+)?$/', $mapsUrl)) {
            $mapsUrl = "https://www.google.com/maps?q=" . str_replace(' ', '', $mapsUrl);
        }

        // 3. Fallback search across past tickets for any missing photos
        $ticketPhotos = Ticket::where(function($query) use ($q, $cleanPrefix, $item, $pelanggan) {
            $query->where('pelanggan_username', $q)
                  ->orWhere('pelanggan_username', 'like', "%{$cleanPrefix}%");
            if ($item && !empty($item->username_pppoe)) {
                $query->orWhere('pelanggan_username', $item->username_pppoe);
            }
            if ($pelanggan && !empty($pelanggan->username)) {
                $query->orWhere('pelanggan_username', $pelanggan->username);
            }
        })
        ->whereNotNull('foto_odp')
        ->orWhereNotNull('foto_redaman')
        ->orWhereNotNull('foto_rumah')
        ->orWhereNotNull('foto_sesudah')
        ->latest('id')
        ->first();

        // Resolving photos with multi-tier fallback (DataSheet -> Pelanggan -> Ticket History)
        $fotoRumahRaw = $item?->foto_rumah_url 
            ?: ($pelanggan?->foto_rumah ? asset('storage/' . $pelanggan->foto_rumah) : '')
            ?: ($ticketPhotos?->foto_rumah ? asset('storage/' . $ticketPhotos->foto_rumah) : '');
        $fotoRumahThumb = DataSheet::resolveGoogleDriveImage($fotoRumahRaw);

        $fotoOdpRaw = $item?->foto_odp_url 
            ?: ($pelanggan?->foto_odp ? asset('storage/' . $pelanggan->foto_odp) : '')
            ?: ($ticketPhotos?->foto_odp ? asset('storage/' . $ticketPhotos->foto_odp) : '');
        $fotoOdpThumb = DataSheet::resolveGoogleDriveImage($fotoOdpRaw);

        $fotoModemRaw = $item?->foto_modem_url 
            ?: ($pelanggan?->foto_identitas_onu ? asset('storage/' . $pelanggan->foto_identitas_onu) : '')
            ?: ($ticketPhotos?->foto_sesudah ? asset('storage/' . $ticketPhotos->foto_sesudah) : '');
        $fotoModemThumb = DataSheet::resolveGoogleDriveImage($fotoModemRaw);

        $fotoRedamanRaw = $item?->foto_redaman_url 
            ?: ($pelanggan?->foto_redaman ? asset('storage/' . $pelanggan->foto_redaman) : '')
            ?: ($ticketPhotos?->foto_redaman ? asset('storage/' . $ticketPhotos->foto_redaman) : '');
        $fotoRedamanThumb = DataSheet::resolveGoogleDriveImage($fotoRedamanRaw);

        $fotoDokumenRaw = $item?->foto_dokumen_url 
            ?: ($pelanggan?->foto_dokumen ? asset('storage/' . $pelanggan->foto_dokumen) : '')
            ?: ($ticketPhotos?->foto_dokumen ? asset('storage/' . $ticketPhotos->foto_dokumen) : '');
        $fotoDokumenThumb = DataSheet::resolveGoogleDriveImage($fotoDokumenRaw);

        $usernameFinal = $item?->username_pppoe ?: ($pelanggan?->username ?: $q);
        $namaFinal     = $item?->nama_pelanggan ?: ($pelanggan?->nama ?: $usernameFinal);
        $idCustFinal   = $item?->nik_ktp ?: ($pelanggan?->id_customer ?: ($rawData['id_customer'] ?? ($usernameFinal ? explode('@', $usernameFinal)[0] : '')));

        return response()->json([
            'found'                  => true,
            'id'                     => $item?->id ?: $pelanggan?->id,
            'id_customer'            => $idCustFinal,
            'username_pppoe'         => $usernameFinal,
            'nama_pelanggan'         => $namaFinal,
            'telepon'                => $mainPhone,
            'telepon_alt'            => $altPhone,
            'alamat'                 => $item?->alamat ?: ($pelanggan?->desa ?: ($item?->nama_odp ? 'Area ODP ' . $item->nama_odp : '')),
            'nama_odp'               => $item?->nama_odp ?: '',
            'port_odp'               => $item?->port_odp ?: ($rawData['port_odp'] ?? ''),
            'vlan'                   => $item?->vlan ?: ($pelanggan?->vlan ?: ($rawData['vlan'] ?? '')),
            'mac_address'            => $item?->mac_address ?: ($pelanggan?->mac_address ?: ($rawData['mac_ont'] ?? '')),
            'pon_sn'                 => $item?->pon_sn ?: ($pelanggan?->pon_sn ?: ($rawData['pon_sn'] ?? '')),
            'serial_number'          => $item?->serial_number ?: ($pelanggan?->serial_number ?: ($rawData['serial_number'] ?? '')),
            'paket'                  => $item?->paket ?: ($pelanggan?->paket ?: ''),
            'harga_paket'            => $item?->harga_paket ? (int)$item->harga_paket : ($pelanggan?->harga_paket ? (int)$pelanggan->harga_paket : null),
            'biaya_pasang'           => $item?->biaya_pasang ? (int)$item->biaya_pasang : ($pelanggan?->biaya_pasang ? (int)$pelanggan->biaya_pasang : null),
            'tanggal_jatuh_tempo'    => $item?->tanggal_jatuh_tempo ?: '',
            'foto_rumah_raw'         => $fotoRumahRaw,
            'foto_rumah_thumb'       => $fotoRumahThumb,
            'foto_odp_raw'           => $fotoOdpRaw,
            'foto_odp_thumb'         => $fotoOdpThumb,
            'foto_modem_raw'         => $fotoModemRaw,
            'foto_modem_thumb'       => $fotoModemThumb,
            'foto_redaman_raw'       => $fotoRedamanRaw,
            'foto_redaman_thumb'     => $fotoRedamanThumb,
            'foto_dokumen_raw'       => $fotoDokumenRaw,
            'foto_dokumen_thumb'     => $fotoDokumenThumb,
            'lokasi_maps'            => $item?->lokasi_maps ?: '',
            'shareloc_url'           => $mapsUrl ?: ($item?->lokasi_maps ?: ''),
            'active_dismantle_ticket'=> $activeTicketInfo,
        ]);
    }

    /**
     * Get suggestions list of PPPoE usernames for auto-completion (Searches both Pelanggan & DataSheet).
     */
    public function suggestions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return response()->json([]);
        }

        $cleanQ = explode('@', $q)[0];
        $results = collect();

        // 1. Search local Pelanggan table (instant response)
        $pelangganItems = Pelanggan::query()
            ->where('username', 'like', "%{$cleanQ}%")
            ->orWhere('nama', 'like', "%{$cleanQ}%")
            ->orWhere('id_customer', 'like', "%{$cleanQ}%")
            ->limit(10)
            ->get();

        foreach ($pelangganItems as $p) {
            $results->push([
                'id'             => $p->id,
                'username_pppoe' => $p->username,
                'nama_pelanggan' => $p->nama ?: $p->username,
                'nama_odp'       => '',
                'telepon'        => '',
                'source'         => 'pelanggan',
            ]);
        }

        // 2. Search DataSheet table
        $dsItems = DataSheet::query()
            ->where('username_pppoe', 'like', "%{$cleanQ}%")
            ->orWhere('nama_pelanggan', 'like', "%{$cleanQ}%")
            ->orWhere('nik_ktp', 'like', "%{$cleanQ}%")
            ->limit(10)
            ->get(['id', 'username_pppoe', 'nama_pelanggan', 'nama_odp', 'telepon', 'paket', 'foto_rumah_url']);

        foreach ($dsItems as $ds) {
            if (!$results->contains('username_pppoe', $ds->username_pppoe)) {
                $results->push([
                    'id'             => $ds->id,
                    'username_pppoe' => $ds->username_pppoe,
                    'nama_pelanggan' => $ds->nama_pelanggan ?: $ds->username_pppoe,
                    'nama_odp'       => $ds->nama_odp ?: '',
                    'telepon'        => $ds->telepon ?: '',
                    'source'         => 'datasheet',
                ]);
            }
        }

        return response()->json($results->values());
    }

    /**
     * Delete customer from DataSheet, local Pelanggan DB, and MikroTik PPPoE Secret permanently.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user || (!$user->isSuperAdmin() && !$user->hasPermission('pelanggan_delete') && !$user->hasPermission('pelanggan') && $user->role !== 'noc' && $user->role !== 'admin')) {
            return response()->json([
                'success' => false,
                'message' => '⛔ Akses ditolak! Anda tidak memiliki wewenang untuk menghapus data pelanggan.',
            ], 403);
        }

        $id = $request->input('id');
        $username = trim((string) $request->input('username_pppoe', ''));

        $item = null;
        if (!empty($id)) {
            $item = DataSheet::find($id);
        }
        if (!$item && !empty($username)) {
            $item = DataSheet::where('username_pppoe', $username)->first();
        }

        $targetUser = $username ?: ($item?->username_pppoe ?? '');
        $namaPelanggan = $item?->nama_pelanggan ?? $targetUser;

        if (empty($targetUser) && empty($id)) {
            return response()->json([
                'success' => false,
                'message' => '⚠️ ID atau Username PPPoE pelanggan wajib disertakan.',
            ], 422);
        }

        // 1. Delete from DataSheet
        if ($item) {
            $item->delete();
        } elseif (!empty($targetUser)) {
            DataSheet::where('username_pppoe', $targetUser)->delete();
        }

        // 2. Delete local Pelanggan table record
        if (!empty($targetUser)) {
            \App\Models\Pelanggan::where('username', $targetUser)->delete();
        }

        // 3. Delete MikroTik PPPoE Secret & Kick active session
        $mtResult = ['success' => true, 'message' => ''];
        if (!empty($targetUser)) {
            try {
                $mikrotik = new MikrotikService();
                $mtResult = $mikrotik->deletePppoeSecret($targetUser);
            } catch (Throwable $e) {
                $mtResult = ['success' => false, 'message' => $e->getMessage()];
            }
        }

        \App\Services\ActivityLogService::log(
            'WARNING',
            'Hapus Pelanggan & DataSheet',
            "Menghapus pelanggan {$targetUser} ({$namaPelanggan}) dari DataSheet, Database & Secret MikroTik",
            $user->nama ?? ($user->username ?? 'Administrator')
        );

        return response()->json([
            'success' => true,
            'message' => "🗑️ Data pelanggan '{$targetUser}' ({$namaPelanggan}) berhasil dihapus permanen dari DataSheet & Router MikroTik!",
            'mikrotik' => $mtResult,
        ]);
    }
}
