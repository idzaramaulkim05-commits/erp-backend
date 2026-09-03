<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\DataSheet;
use App\Models\Paket;
use App\Models\Router;
use App\Models\Setting;
use App\Services\ExcelExportHelper;
use App\Services\MikrotikService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class SyncCheckController extends Controller
{
    protected static array $inMemoryViewCache = [];

    /**
     * Display the full cross-audit comparison dashboard.
     */
    public function index(Request $request)
    {
        $setting = Setting::getSetting();
        $deviceId = $request->query('router_id');
        $device = $deviceId ? Router::find($deviceId) : Router::getDefaultRouter();
        $routers = Router::where('is_active', true)->orderBy('is_default', 'desc')->get();

        $forceRefresh = $request->has('refresh') || $request->query('refresh') === '1';

        $tab = $request->query('tab', 'sheet'); // 'sheet' or 'mikrotik'
        $filter = $request->query('filter', 'all');
        $q = trim((string)$request->query('q', ''));
        $odpFilter = trim((string)$request->query('odp', 'all'));
        $paketFilter = trim((string)$request->query('paket', 'all'));
        $perPage = max(10, min(200, (int)$request->query('per_page', 50)));
        $page = (int) $request->query('page', 1);

        $cacheHash = ($device?->id ?? 'default') . '|' . $tab . '|' . $filter . '|' . $q . '|' . $odpFilter . '|' . $paketFilter . '|' . $page . '|' . $perPage;
        if ($forceRefresh) {
            self::$inMemoryViewCache = [];
            Cache::forget('mikrotik_pppoe_secrets_' . ($device?->id ?? 'default'));
            Cache::forget('mikrotik_telemetry_' . ($device?->id ?? 'default'));
        }

        if (!$forceRefresh && isset(self::$inMemoryViewCache[$cacheHash])) {
            $bundle = self::$inMemoryViewCache[$cacheHash];
        } else {
            // 1. Fetch PPPoE Secrets from MikroTik
            $mikrotik = new MikrotikService($device);
            $secrets = $mikrotik->getPppoeSecrets(!$forceRefresh);
            $isConnected = $mikrotik->isConnected();

            // 2. Fetch indexed DataSheet records & Master Pakets
            $sheetUserMap = DataSheet::getSheetMap();
            $masterPakets = Paket::getActivePakets();

            // 3. Index Secrets with smart aliases & prefix map for ultra-fast O(1) matching
            $secretMap = [];
            $secretPrefixMap = [];
            foreach ($secrets as $s) {
                $uRaw = (string)($s['username'] ?? '');
                $aliases = DataSheet::generateAliases($uRaw);
                foreach ($aliases as $al) {
                    if (!isset($secretMap[$al])) {
                        $secretMap[$al] = $s;
                    }
                }
                $u = strtolower(trim($uRaw));
                if ($u !== '') {
                    $secretMap[$u] = $s;
                }
                if (preg_match('/^(\d{3,5})[-_]/', DataSheet::normalizeKey($uRaw), $mPref)) {
                    if (!isset($secretPrefixMap[$mPref[1]])) {
                        $secretPrefixMap[$mPref[1]] = $s;
                    }
                }
            }

            // 4. Compile Top KPI Audit Summary Statistics with Master Paket & Dismantle Resolution
            $allDataSheets = DataSheet::select('id', 'username_pppoe', 'nama_pelanggan', 'nik_ktp', 'nama_odp', 'port_odp', 'alamat', 'telepon', 'paket', 'status_langganan', 'raw_data')->get();
            $totalSheet = $allDataSheets->count();
            $totalSecrets = count($secrets);

            $sheetAktif = 0;
            $sheetDismantle = 0;
            $sheetMatchedInMt = 0;
            $sheetMissingAktif = 0;
            $sheetDismantleDeletedInMt = 0;
            $sheetDismantleStillInMt = 0;
            $sheetNoUsername = 0;
            $sheetProfileDiff = 0;

            foreach ($allDataSheets as $ds) {
                $isDismantle = ($ds->status_langganan === 'dismantle' || $ds->status_langganan === 'uninstall' || $ds->status_langganan === 'unistall');
                if ($isDismantle) {
                    $sheetDismantle++;
                } else {
                    $sheetAktif++;
                }

                $u = strtolower(trim((string)$ds->username_pppoe));
                if (empty($u) || $u === '-' || str_starts_with($u, 'pelanggan_row_')) {
                    $sheetNoUsername++;
                    if ($isDismantle) {
                        $sheetDismantleDeletedInMt++;
                    } else {
                        $sheetMissingAktif++;
                    }
                } else {
                    $matchedSec = null;
                    $aliases = DataSheet::generateAliases((string)$ds->username_pppoe);
                    foreach ($aliases as $al) {
                        if (isset($secretMap[$al])) {
                            $matchedSec = $secretMap[$al];
                            break;
                        }
                    }
                    if (!$matchedSec && preg_match('/^(\d{3,5})[-_]/', DataSheet::normalizeKey((string)$ds->username_pppoe), $mP)) {
                        if (isset($secretPrefixMap[$mP[1]])) {
                            $matchedSec = $secretPrefixMap[$mP[1]];
                        }
                    }

                    if ($matchedSec) {
                        $sheetMatchedInMt++;
                        if ($isDismantle) {
                            $sheetDismantleStillInMt++;
                        }
                        $secProf = trim((string)($matchedSec['profile'] ?? ''));
                        $dsProf = trim((string)($ds->paket ?? ''));

                        if (!self::isPackageMatching($dsProf, $secProf, $masterPakets)) {
                            $sheetProfileDiff++;
                        }
                    } else {
                        if ($isDismantle) {
                            $sheetDismantleDeletedInMt++;
                        } else {
                            $sheetMissingAktif++;
                        }
                    }
                }
            }

            $secretInSheet = 0;
            $secretNotInSheet = 0;
            $secretOnline = 0;
            $secretDisabled = 0;

            foreach ($secrets as $s) {
                $uRaw = (string)($s['username'] ?? '');
                $matchedSheet = DataSheet::findMatchForSecret($uRaw);
                if ($matchedSheet) {
                    $secretInSheet++;
                } else {
                    $secretNotInSheet++;
                }

                if (($s['status'] ?? '') === 'Online' || ($s['status_category'] ?? '') === 'online') {
                    $secretOnline++;
                }
                if (($s['disabled'] ?? false) || ($s['status_category'] ?? '') === 'disabled') {
                    $secretDisabled++;
                }
            }

            $sinkronOk = $secretInSheet;
            $totalSelisih = $sheetMissingAktif + $secretNotInSheet;

            $stats = [
                'total_sheet'                  => $totalSheet,
                'sheet_aktif'                  => $sheetAktif,
                'sheet_dismantle'              => $sheetDismantle,
                'sheet_matched'                => $sheetMatchedInMt,
                'sheet_missing_aktif'          => $sheetMissingAktif,
                'sheet_dismantle_deleted'      => $sheetDismantleDeletedInMt,
                'sheet_dismantle_still_in_mt'  => $sheetDismantleStillInMt,
                'sheet_missing'                => $sheetMissingAktif,
                'sheet_no_user'                => $sheetNoUsername,
                'sheet_profile_diff'           => $sheetProfileDiff,
                'total_secrets'                => $totalSecrets,
                'secret_in_sheet'              => $secretInSheet,
                'secret_not_in_sheet'          => $secretNotInSheet,
                'secret_online'                => $secretOnline,
                'secret_disabled'              => $secretDisabled,
                'sinkron_ok'                   => $sinkronOk,
                'total_selisih'                => $totalSelisih,
            ];

            $statsCache = \Illuminate\Support\Facades\Cache::get('datasheet_index_stats_v1') ?? [];
            $allOdps = $statsCache['allOdps'] ?? DataSheet::whereNotNull('nama_odp')
                ->where('nama_odp', '!=', '-')
                ->where('nama_odp', '!=', '')
                ->distinct()
                ->orderBy('nama_odp', 'asc')
                ->pluck('nama_odp')
                ->toArray();

            $allPakets = $statsCache['allPakets'] ?? DataSheet::whereNotNull('paket')
                ->where('paket', '!=', '')
                ->distinct()
                ->orderBy('paket', 'asc')
                ->pluck('paket')
                ->toArray();

            // 7. Tab 1: Pelanggan Data Sheet List
            if ($tab === 'sheet') {
                $sheetQuery = DataSheet::select('id', 'username_pppoe', 'nama_pelanggan', 'nik_ktp', 'id_customer', 'nama_odp', 'port_odp', 'alamat', 'telepon', 'paket', 'status_langganan');

                if ($q !== '') {
                    $terms = array_filter(explode(' ', $q));
                    $sheetQuery->where(function ($w) use ($terms) {
                        foreach ($terms as $term) {
                            $w->where(function ($sub) use ($term) {
                                $sub->where('username_pppoe', 'like', "%{$term}%")
                                    ->orWhere('nama_pelanggan', 'like', "%{$term}%")
                                    ->orWhere('nama_odp', 'like', "%{$term}%")
                                    ->orWhere('telepon', 'like', "%{$term}%")
                                    ->orWhere('nik_ktp', 'like', "%{$term}%")
                                    ->orWhere('paket', 'like', "%{$term}%")
                                    ->orWhere('alamat', 'like', "%{$term}%");
                            });
                        }
                    });
                }

                if ($odpFilter !== '' && $odpFilter !== 'all') {
                    $sheetQuery->where('nama_odp', $odpFilter);
                }

                if ($paketFilter !== '' && $paketFilter !== 'all') {
                    $sheetQuery->where('paket', $paketFilter);
                }

                $allFilteredSheet = $sheetQuery->get();

                // Apply in-memory matching state filter with Master Paket resolution
                $filteredItems = $allFilteredSheet->filter(function ($ds) use ($filter, $secretMap, $secretPrefixMap, $masterPakets) {
                    $u = strtolower(trim((string)$ds->username_pppoe));
                    $isDismantle = ($ds->status_langganan === 'dismantle' || $ds->status_langganan === 'uninstall' || $ds->status_langganan === 'unistall');
                    $matchedSec = null;
                    $aliases = DataSheet::generateAliases((string)$ds->username_pppoe);
                    foreach ($aliases as $al) {
                        if (isset($secretMap[$al])) {
                            $matchedSec = $secretMap[$al];
                            break;
                        }
                    }
                    if (!$matchedSec && preg_match('/^(\d{3,5})[-_]/', DataSheet::normalizeKey((string)$ds->username_pppoe), $mP)) {
                        if (isset($secretPrefixMap[$mP[1]])) {
                            $matchedSec = $secretPrefixMap[$mP[1]];
                        }
                    }

                    $hasSecret = $matchedSec !== null;
                    $isNoUser = empty($u) || $u === '-' || str_starts_with($u, 'pelanggan_row_');

                    if ($filter === 'synced') {
                        return $hasSecret && !$isNoUser;
                    }
                    if ($filter === 'missing') {
                        return !$hasSecret && !$isNoUser && !$isDismantle;
                    }
                    if ($filter === 'dismantle') {
                        return $isDismantle;
                    }
                    if ($filter === 'no_user') {
                        return $isNoUser;
                    }
                    if ($filter === 'profile_diff') {
                        if (!$hasSecret || $isNoUser) return false;
                        $secProf = trim((string)($matchedSec['profile'] ?? ''));
                        $dsProf = trim((string)($ds->paket ?? ''));

                        return !self::isPackageMatching($dsProf, $secProf, $masterPakets);
                    }
                    return true;
                });

                // Paginate in-memory collection
                $currentPage = LengthAwarePaginator::resolveCurrentPage();
                $pagedItems = $filteredItems->slice(($currentPage - 1) * $perPage, $perPage)->values();
                $paginator = new LengthAwarePaginator(
                    $pagedItems,
                    $filteredItems->count(),
                    $perPage,
                    $currentPage,
                    ['path' => $request->url(), 'query' => $request->query()]
                );

                $bundle = [
                    'isConnected'    => $isConnected,
                    'stats'          => $stats,
                    'allOdps'        => $allOdps,
                    'allPakets'      => $allPakets,
                    'paginator'      => $paginator,
                    'secretMap'      => $secretMap,
                    'secretPrefixMap'=> $secretPrefixMap,
                    'sheetUserMap'   => $sheetUserMap,
                ];
            } else {
                // 8. Tab 2: PPPoE MikroTik List
                $filteredSecrets = collect($secrets)->filter(function ($s) use ($q, $filter) {
                    $username = strtolower((string)($s['username'] ?? ''));
                    $name = strtolower((string)($s['name'] ?? ''));
                    $profile = strtolower((string)($s['profile'] ?? ''));
                    $ip = strtolower((string)($s['ip'] ?? ''));
                    $matchedSheet = DataSheet::findMatchForSecret($username);
                    $hasSheet = $matchedSheet !== null;

                    // Keyword Search
                    if ($q !== '') {
                        $qLower = strtolower($q);
                        $match = str_contains($username, $qLower) 
                              || str_contains($name, $qLower) 
                              || str_contains($profile, $qLower) 
                              || str_contains($ip, $qLower)
                              || ($hasSheet && (str_contains(strtolower($matchedSheet['nama_pelanggan']), $qLower) || str_contains(strtolower($matchedSheet['telepon']), $qLower)));
                        if (!$match) {
                            return false;
                        }
                    }

                    // Subfilter
                    if ($filter === 'in_sheet') {
                        return $hasSheet;
                    }
                    if ($filter === 'not_in_sheet') {
                        return !$hasSheet;
                    }
                    if ($filter === 'online') {
                        return ($s['status'] ?? '') === 'Online' || ($s['status_category'] ?? '') === 'online';
                    }
                    if ($filter === 'disabled') {
                        return ($s['disabled'] ?? false) || ($s['status_category'] ?? '') === 'disabled';
                    }

                    return true;
                });

                $currentPage = LengthAwarePaginator::resolveCurrentPage();
                $pagedSecrets = $filteredSecrets->slice(($currentPage - 1) * $perPage, $perPage)->values();
                $paginator = new LengthAwarePaginator(
                    $pagedSecrets,
                    $filteredSecrets->count(),
                    $perPage,
                    $currentPage,
                    ['path' => $request->url(), 'query' => $request->query()]
                );

                $bundle = [
                    'isConnected'    => $isConnected,
                    'stats'          => $stats,
                    'allOdps'        => $allOdps,
                    'allPakets'      => $allPakets,
                    'paginator'      => $paginator,
                    'secretMap'      => $secretMap,
                    'secretPrefixMap'=> $secretPrefixMap,
                    'sheetUserMap'   => $sheetUserMap,
                ];
            }

            self::$inMemoryViewCache[$cacheHash] = $bundle;
        }

        return view('datasheet.sync_check', array_merge([
            'page'           => 'datasheet_sync',
            'setting'        => $setting,
            'routers'        => $routers,
            'selectedRouter' => $device,
            'tab'            => $tab,
            'filter'         => $filter,
            'q'              => $q,
            'odpFilter'      => $odpFilter,
            'paketFilter'    => $paketFilter,
            'masterPakets'   => Paket::getActivePakets(),
        ], $bundle));
    }

    /**
     * Export comparison audit data to Excel / CSV.
     */
    public function export(Request $request)
    {
        $routerId = $request->query('router_id');
        $device = $routerId ? Router::find($routerId) : Router::getDefaultRouter();
        $mikrotik = new MikrotikService($device);
        $secrets = $mikrotik->getPppoeSecrets(true);
        $dataSheets = DataSheet::all();

        $secretMap = [];
        foreach ($secrets as $s) {
            $u = strtolower(trim((string)($s['username'] ?? '')));
            if ($u !== '') {
                $secretMap[$u] = $s;
            }
        }

        $sheetUserMap = [];
        foreach ($dataSheets as $ds) {
            $u = strtolower(trim((string)$ds->username_pppoe));
            if ($u !== '') {
                $sheetUserMap[$u] = $ds;
            }
        }

        $tab = $request->query('tab', 'sheet');
        $dateSuffix = date('Ymd_His');

        if ($tab === 'sheet') {
            $filename = "Audit_Sinkron_DataSheet_vs_Mikrotik_{$dateSuffix}";
            $headers = [
                'No',
                'ID / NIK',
                'Nama Pelanggan',
                'Username PPPoE (Sheet)',
                'Paket di Sheet',
                'Profile di MikroTik',
                'Status Sinkron MT',
                'Status Live MT',
                'IP Aktif MT',
                'Router',
                'Wilayah / ODP',
                'No. Telepon / WA',
                'Alamat Lengkap',
            ];

            $rows = [];
            $no = 1;
            foreach ($dataSheets as $ds) {
                $u = strtolower(trim((string)$ds->username_pppoe));
                $hasSecret = isset($secretMap[$u]);
                $sec = $hasSecret ? $secretMap[$u] : null;

                $statusSinkron = 'BELUM DI MIKROTIK (MISSING)';
                if (empty($u) || $u === '-' || str_starts_with($u, 'pelanggan_row_')) {
                    $statusSinkron = 'TANPA USERNAME PPPOE';
                } elseif ($hasSecret) {
                    $statusSinkron = 'ADA DI MIKROTIK (SINKRON OK)';
                }

                $statusLive = $sec ? ($sec['status'] ?? 'Offline') : '-';
                $ipAktif = $sec ? ($sec['ip'] ?? '-') : '-';
                $profileMt = $sec ? ($sec['profile'] ?? '-') : '-';

                $rows[] = [
                    $no++,
                    $ds->nik_ktp ?: '-',
                    $ds->nama_pelanggan,
                    $ds->username_pppoe,
                    $ds->paket ?: '-',
                    $profileMt,
                    $statusSinkron,
                    $statusLive,
                    $ipAktif,
                    $device?->name ?? 'MikroTik Utama',
                    $ds->nama_odp ?: '-',
                    $ds->telepon ?: '-',
                    $ds->alamat ?: '-',
                ];
            }

            return ExcelExportHelper::streamExport($filename, 'DataSheet_Audit', $headers, $rows, $request->query('format', 'excel'));
        }

        // Export MikroTik Secrets Audit
        $filename = "Audit_Sinkron_Secret_Mikrotik_vs_DataSheet_{$dateSuffix}";
        $headers = [
            'No',
            'Username Secret MT',
            'Profile MT',
            'Status di Data Sheet',
            'Nama di Data Sheet',
            'Paket di Data Sheet',
            'Wilayah / ODP',
            'Service',
            'Status Live',
            'IP Address',
            'Caller ID (MAC)',
            'Disabled',
            'Last Logged Out',
        ];

        $rows = [];
        $no = 1;
        foreach ($secrets as $s) {
            $u = strtolower(trim((string)($s['username'] ?? '')));
            $hasSheet = isset($sheetUserMap[$u]);
            $ds = $hasSheet ? $sheetUserMap[$u] : null;

            $statusInSheet = $hasSheet ? 'TERDAFTAR DI DATA SHEET' : 'TIDAK ADA DI DATA SHEET (ORPHAN)';

            $rows[] = [
                $no++,
                $s['username'],
                $s['profile'] ?? '-',
                $statusInSheet,
                $ds?->nama_pelanggan ?? '-',
                $ds?->paket ?? '-',
                $ds?->nama_odp ?? '-',
                $s['service'] ?? 'pppoe',
                $s['status'] ?? 'Offline',
                $s['ip'] ?? '-',
                $s['caller_id'] ?? '-',
                ($s['disabled'] ?? false) ? 'YES' : 'NO',
                $s['last_logged_out'] ?? '-',
            ];
        }

        return ExcelExportHelper::streamExport($filename, 'Secret_MT_Audit', $headers, $rows, $request->query('format', 'excel'));
    }

    /**
     * Check if a Sheet package name matches a MikroTik profile name via Master Paket definitions.
     */
    public static function isPackageMatching(?string $sheetPaketStr, ?string $mtProfileStr, $allPakets): bool
    {
        if (empty($sheetPaketStr) || empty($mtProfileStr)) {
            return false;
        }

        $cleanSheet = self::normalizePackageKey($sheetPaketStr);
        $cleanMt = self::normalizePackageKey($mtProfileStr);

        if ($cleanSheet === $cleanMt) {
            return true;
        }

        // 1. Check via Master Paket Database
        foreach ($allPakets as $p) {
            $pNama = self::normalizePackageKey($p->nama_paket);
            $pProfile = self::normalizePackageKey($p->mikrotik_profile);

            $sheetMatchesPaket = ($cleanSheet === $pNama || $cleanSheet === $pProfile || str_contains($cleanSheet, $pNama) || str_contains($pNama, $cleanSheet));
            $mtMatchesPaket = ($cleanMt === $pProfile || $cleanMt === $pNama || str_contains($cleanMt, $pProfile) || str_contains($pProfile, $cleanMt));

            if ($sheetMatchesPaket && $mtMatchesPaket) {
                return true;
            }

            // Check tariff number (e.g. 199k, 170k, 179k, 235k, 285k, 345k, 645k)
            if (preg_match('/(\d{3,4})k/i', $sheetPaketStr, $m1) && preg_match('/(\d{3,4})k/i', $mtProfileStr, $m2)) {
                if ($m1[1] === $m2[1]) {
                    return true;
                }
            }
            if (preg_match('/(\d{2,3})\s*mbps/i', $sheetPaketStr, $b1) && preg_match('/(\d{2,3})\s*mbps/i', $mtProfileStr, $b2)) {
                if (preg_match('/(\d{3,4})k/i', $sheetPaketStr, $m1) && preg_match('/(\d{3,4})k/i', $mtProfileStr, $m2)) {
                    if ($b1[1] === $b2[1] && $m1[1] === $m2[1]) {
                        return true;
                    }
                }
            }
        }

        // Keyword containment fallback
        if (str_contains($cleanSheet, $cleanMt) || str_contains($cleanMt, $cleanSheet)) {
            return true;
        }

        return false;
    }

    private static function normalizePackageKey(?string $str): string
    {
        $s = strtolower(trim((string)$str));
        $s = preg_replace('/[^a-z0-9]/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }
}
