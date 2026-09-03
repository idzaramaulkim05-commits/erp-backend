<?php

namespace App\Services;

use App\Models\DataSheet;
use App\Models\Invoice;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    /**
     * Generate monthly invoices for all active customers.
     *
     * Rules:
     * 1. Skip customers with status UNISTALL / UNINSTALL / CABUT / DISMANTLE.
     * 2. Skip customers with unpaid backlog invoices from previous periods.
     * 3. Skip if invoice already generated for the requested period.
     *
     * @param int $bulan (1-12)
     * @param int $tahun (e.g. 2026)
     * @param int|null $userId
     * @return array
     */
    public function generateMonthlyInvoices(int $bulan, int $tahun, ?int $userId = null): array
    {
        $allPakets = Paket::where('is_active', true)->get();
        $pakets = $allPakets->keyBy(function ($p) {
            return strtolower(trim($p->nama_paket));
        });

        // Load all active customer records from DataSheet
        $dataSheets = DataSheet::all();
        $pelangganDb = Pelanggan::all()->keyBy(function ($p) {
            return strtolower(trim($p->username));
        });

        $processed = 0;
        $created = 0;
        $skippedUninstalled = 0;
        $skippedUnpaidBacklog = 0;
        $skippedAlreadyExists = 0;
        $totalNominal = 0;

        $tglInvoice = Carbon::createFromDate($tahun, $bulan, 1)->format('Y-m-d');

        // Customer deduplication cache by username
        $seenUsernames = [];

        DB::beginTransaction();
        try {
            foreach ($dataSheets as $sheet) {
                $username = trim((string) $sheet->username_pppoe);
                if (!$username) {
                    continue;
                }

                $userKey = strtolower($username);
                if (isset($seenUsernames[$userKey])) {
                    continue;
                }
                $seenUsernames[$userKey] = true;
                $processed++;

                // 1. Check Customer Status from DataSheet & Pelanggan
                $raw = is_array($sheet->raw_data) ? $sheet->raw_data : (json_decode($sheet->raw_data ?? '[]', true) ?: []);
                $statusCol = strtoupper(trim((string) ($raw[12] ?? ($raw['status'] ?? ($raw['status_pelanggan'] ?? ($sheet->status_langganan ?? ''))))));
                
                $pelMatch = $pelangganDb->get($userKey);
                $pelStatus = strtoupper(trim((string) ($pelMatch->status ?? '')));

                // Check for UNISTALL / UNINSTALL / CABUT / DISMANTLE
                if (
                    str_contains($statusCol, 'UNISTALL') || 
                    str_contains($statusCol, 'UNINSTALL') || 
                    str_contains($statusCol, 'CABUT') || 
                    str_contains($statusCol, 'DISMANTLE') ||
                    str_contains($statusCol, 'NON-AKTIF') ||
                    str_contains($statusCol, 'NONAKTIF') ||
                    str_contains($pelStatus, 'UNISTALL') ||
                    str_contains($pelStatus, 'UNINSTALL') ||
                    str_contains($pelStatus, 'CABUT') ||
                    str_contains($pelStatus, 'DISMANTLE')
                ) {
                    $skippedUninstalled++;
                    continue;
                }

                // 2. Check if invoice for this period already exists
                $existsThisMonth = Invoice::where('pelanggan_username', $username)
                    ->where('periode_bulan', $bulan)
                    ->where('periode_tahun', $tahun)
                    ->exists();

                if ($existsThisMonth) {
                    $skippedAlreadyExists++;
                    continue;
                }

                // 3. Check for unpaid backlog invoice from previous months
                $hasUnpaidPrevious = Invoice::where('pelanggan_username', $username)
                    ->where('status', 'belum_lunas')
                    ->where(function ($q) use ($bulan, $tahun) {
                        $q->where('periode_tahun', '<', $tahun)
                          ->orWhere(function ($sub) use ($bulan, $tahun) {
                              $sub->where('periode_tahun', $tahun)
                                  ->where('periode_bulan', '<', $bulan);
                          });
                    })
                    ->exists();

                if ($hasUnpaidPrevious) {
                    $skippedUnpaidBacklog++;
                    continue;
                }

                // 4. Resolve package & pricing from Master Paket
                $paketName = $sheet->paket ?: ($pelMatch->paket ?? 'PAKET STANDARD');
                $cleanPaketKey = strtolower(trim($paketName));
                $matchedPaket = null;

                foreach ($allPakets as $p) {
                    if (strtolower(trim($p->nama_paket)) === $cleanPaketKey || ($p->mikrotik_profile && strtolower(trim($p->mikrotik_profile)) === $cleanPaketKey)) {
                        $matchedPaket = $p;
                        break;
                    }
                }

                $harga = (float) ($sheet->harga_paket > 0 ? $sheet->harga_paket : ($pelMatch->harga_paket ?? 0));
                if (!$matchedPaket && $harga > 0) {
                    foreach ($allPakets as $p) {
                        if (abs((float)$p->tarif_bulanan - $harga) < 100) {
                            $matchedPaket = $p;
                            break;
                        }
                    }
                }

                if (!$matchedPaket && preg_match('/(\d{3,4})k/i', $cleanPaketKey, $m)) {
                    $kPrice = (int)$m[1] * 1000;
                    foreach ($allPakets as $p) {
                        if (abs((float)$p->tarif_bulanan - $kPrice) < 1000) {
                            $matchedPaket = $p;
                            break;
                        }
                    }
                }

                if ($harga <= 0) {
                    $harga = $matchedPaket ? (float) $matchedPaket->tarif_bulanan : 150000;
                }

                // Check PPN from Master Paket
                $ppnRate = $matchedPaket ? (float) $matchedPaket->ppn : 11.0;
                if ($matchedPaket && $matchedPaket->harga_dasar > 0 && $matchedPaket->tarif_bulanan > 0) {
                    $hargaDasar = (float)$matchedPaket->harga_dasar;
                    $tax = max(0, (float)$matchedPaket->tarif_bulanan - $hargaDasar);
                    $totalTagihan = (float)$matchedPaket->tarif_bulanan;
                } else {
                    $totalTagihan = $harga;
                    $hargaDasar = round($totalTagihan / (1 + ($ppnRate / 100)));
                    $tax = max(0, $totalTagihan - $hargaDasar);
                }

                // Customer Identification & metadata
                $customerName = $sheet->nama_pelanggan ?: ($pelMatch->nama ?? $username);
                $idCust = $raw['id_customer'] ?? ($pelMatch->id_customer ?? null);
                if ($idCust === $sheet->nik_ktp) {
                    $idCust = null;
                }
                $telepon = $sheet->telepon ?: ($pelMatch->telepon ?? ($raw[3] ?? null));
                $alamat = $sheet->alamat ?: ($pelMatch->alamat ?? ($raw[5] ?? null));
                
                // Column [1] is Kategori Pelanggan (MR, MRS, CORPORATE)
                $kategoriPelanggan = trim((string) ($raw[1] ?? ($pelMatch->kategori ?? 'RETAIL')));
                if (!$kategoriPelanggan || $kategoriPelanggan === '-') $kategoriPelanggan = 'RETAIL';

                // Column [10] is Marketing / Sales Name (AMEL, TEAM, etc.)
                $marketingPic = trim((string) ($raw[10] ?? ($sheet->sales_name ?? 'Marketing EONET')));
                if (!$marketingPic || $marketingPic === '-') $marketingPic = 'Marketing EONET';

                // Column [11] is Teknisi PIC
                $teknisiPic = trim((string) ($raw[11] ?? 'Teknisi EONET'));
                if (!$teknisiPic || $teknisiPic === '-') $teknisiPic = 'Teknisi EONET';

                // Resolve Customer-Specific Tanggal Jatuh Tempo from Column I (raw[8]) / Tanggal Instalasi
                $tglJatuhTempo = self::resolveJatuhTempoDate($tahun, $bulan, $sheet, $pelMatch);

                // Generate clean sequential invoice number: INV-YYYYMM-XXXX (Strictly Non-Duplicate)
                $seq = $created + $skippedAlreadyExists + 1;
                $nomorInvoice = 'INV-' . $tahun . sprintf('%02d', $bulan) . '-' . sprintf('%04d', $seq);
                while (Invoice::where('nomor_invoice', $nomorInvoice)->exists()) {
                    $seq++;
                    $nomorInvoice = 'INV-' . $tahun . sprintf('%02d', $bulan) . '-' . sprintf('%04d', $seq);
                }

                Invoice::create([
                    'nomor_invoice'       => $nomorInvoice,
                    'id_customer'         => $idCust,
                    'pelanggan_username'  => $username,
                    'pelanggan_nama'      => $customerName,
                    'kategori_pelanggan'  => $kategoriPelanggan,
                    'pelanggan_telepon'   => $telepon,
                    'pelanggan_alamat'    => $alamat,
                    'marketing_pic'       => $marketingPic,
                    'teknisi_pic'         => $teknisiPic,
                    'paket_nama'          => $paketName,
                    'harga_paket'         => $hargaDasar,
                    'tax'                 => $tax,
                    'potongan'            => 0,
                    'total_tagihan'       => $totalTagihan,
                    'total_dibayar'       => 0,
                    'sisa_piutang'        => $totalTagihan,
                    'periode_bulan'       => $bulan,
                    'periode_tahun'       => $tahun,
                    'tanggal_invoice'     => $tglInvoice,
                    'tanggal_jatuh_tempo' => $tglJatuhTempo,
                    'status'              => 'belum_lunas',
                    'created_by'          => $userId,
                ]);

                $created++;
                $totalNominal += $totalTagihan;
            }

            DB::commit();

            return [
                'success'                 => true,
                'total_processed'         => $processed,
                'created_count'           => $created,
                'skipped_uninstalled'     => $skippedUninstalled,
                'skipped_unpaid_backlog'  => $skippedUnpaidBacklog,
                'skipped_already_exists'  => $skippedAlreadyExists,
                'total_nominal'           => $totalNominal,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Gagal generate invoice bulanan: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a single invoice manually.
     */
    public function createSingleInvoice(array $data, ?int $userId = null): Invoice
    {
        $bulan = (int) ($data['periode_bulan'] ?? now()->month);
        $tahun = (int) ($data['periode_tahun'] ?? now()->year);
        $harga = (float) ($data['harga_paket'] ?? 0);
        $biayaPasang = (float) ($data['biaya_pasang'] ?? ($data['biaya_pemasangan'] ?? 0));
        $tax = (float) ($data['tax'] ?? 0);
        $potongan = (float) ($data['potongan'] ?? 0);
        $isPsb = !empty($data['is_psb']) || $biayaPasang > 0 || ($data['kategori_pelanggan'] ?? '') === 'PSB';
        $kategori = $isPsb ? 'PSB' : (!empty($data['kategori_pelanggan']) ? $data['kategori_pelanggan'] : 'BULANAN');
        $totalTagihan = max(0, $harga + $biayaPasang + $tax - $potongan);

        $prefix = $isPsb ? ('INV-PSB-' . $tahun . sprintf('%02d', $bulan) . '-') : ('INV-' . $tahun . sprintf('%02d', $bulan) . '-');
        $seq = Invoice::where('periode_bulan', $bulan)->where('periode_tahun', $tahun)->count() + 1;
        $nomorInvoice = !empty($data['nomor_invoice']) ? trim($data['nomor_invoice']) : ($prefix . sprintf('%04d', $seq));
        while (Invoice::where('nomor_invoice', $nomorInvoice)->exists()) {
            $seq++;
            $nomorInvoice = $prefix . sprintf('%04d', $seq);
        }

        return Invoice::create([
            'nomor_invoice'       => $nomorInvoice,
            'id_customer'         => $data['id_customer'] ?? null,
            'pelanggan_username'  => $data['pelanggan_username'],
            'pelanggan_nama'      => $data['pelanggan_nama'],
            'kategori_pelanggan'  => $kategori,
            'pelanggan_telepon'   => $data['pelanggan_telepon'] ?? null,
            'pelanggan_alamat'    => $data['pelanggan_alamat'] ?? null,
            'marketing_pic'       => $data['marketing_pic'] ?? 'Marketing EONET',
            'teknisi_pic'         => $data['teknisi_pic'] ?? 'Teknisi EONET',
            'paket_nama'          => $data['paket_nama'],
            'harga_paket'         => $harga,
            'biaya_pasang'        => $biayaPasang,
            'tax'                 => $tax,
            'potongan'            => $potongan,
            'total_tagihan'       => $totalTagihan,
            'total_dibayar'       => 0,
            'sisa_piutang'        => $totalTagihan,
            'periode_bulan'       => $bulan,
            'periode_tahun'       => $tahun,
            'tanggal_invoice'     => $data['tanggal_invoice'] ?? now()->toDateString(),
            'tanggal_jatuh_tempo' => !empty($data['tanggal_jatuh_tempo']) ? $data['tanggal_jatuh_tempo'] : self::resolveJatuhTempoDate($tahun, $bulan, \App\Models\DataSheet::where('username_pppoe', $data['pelanggan_username'])->first(), \App\Models\Pelanggan::where('username', $data['pelanggan_username'])->first()),
            'status'              => 'belum_lunas',
            'keterangan'          => $data['keterangan'] ?? null,
            'created_by'          => $userId,
        ]);
    }

    /**
     * Process Invoice Payment.
     */
    public function payInvoice(int $invoiceId, array $data, ?int $userId = null): Invoice
    {
        $invoice = Invoice::findOrFail($invoiceId);
        $amount = isset($data['nominal_bayar']) ? (float) $data['nominal_bayar'] : (float) $invoice->sisa_piutang;
        $newTotalDibayar = (float) $invoice->total_dibayar + $amount;
        $newSisaPiutang = max(0, (float) $invoice->total_tagihan - $newTotalDibayar);

        $status = $newSisaPiutang <= 0 ? 'lunas' : 'belum_lunas';

        $invoice->update([
            'total_dibayar'     => $newTotalDibayar,
            'sisa_piutang'      => $newSisaPiutang,
            'status'            => $status,
            'metode_pembayaran' => $data['metode_pembayaran'] ?? 'CASH',
            'tanggal_bayar'     => $data['tanggal_bayar'] ?? now(),
            'bukti_bayar'       => $data['bukti_bayar'] ?? $invoice->bukti_bayar,
            'keterangan'        => $data['keterangan'] ?? $invoice->keterangan,
            'verified_by'       => $userId,
        ]);

        return $invoice;
    }

    /**
     * Calculate KPI metrics for Data Invoice page.
     */
    public function getInvoiceKpis(?int $bulan = null, ?int $tahun = null, ?string $startDate = null, ?string $endDate = null, string $dateCol = 'tanggal_invoice'): array
    {
        $query = Invoice::query();

        if ($startDate && $endDate) {
            $startBoundary = substr($startDate, 0, 10) . ' 00:00:00';
            $endBoundary = substr($endDate, 0, 10) . ' 23:59:59';
            $query->whereBetween($dateCol, [$startBoundary, $endBoundary]);
        } elseif ($startDate) {
            $startBoundary = substr($startDate, 0, 10) . ' 00:00:00';
            $query->where($dateCol, '>=', $startBoundary);
        } elseif ($endDate) {
            $endBoundary = substr($endDate, 0, 10) . ' 23:59:59';
            $query->where($dateCol, '<=', $endBoundary);
        } else {
            if ($bulan) {
                if ($dateCol === 'tanggal_jatuh_tempo') {
                    $query->whereMonth('tanggal_jatuh_tempo', $bulan);
                } else {
                    $query->where('periode_bulan', $bulan);
                }
            }
            if ($tahun) {
                if ($dateCol === 'tanggal_jatuh_tempo') {
                    $query->whereYear('tanggal_jatuh_tempo', $tahun);
                } else {
                    $query->where('periode_tahun', $tahun);
                }
            }
        }

        $stats = $query->selectRaw("
            COUNT(*) as total_invoices,
            COALESCE(SUM(CASE WHEN status = 'belum_lunas' THEN sisa_piutang ELSE 0 END), 0) as nominal_belum_dibayar,
            COALESCE(SUM(CASE WHEN status = 'lunas' THEN total_dibayar ELSE 0 END), 0) as nominal_lunas,
            COALESCE(SUM(CASE WHEN status = 'lunas' THEN 1 ELSE 0 END), 0) as total_lunas,
            COALESCE(SUM(CASE WHEN status = 'belum_lunas' THEN 1 ELSE 0 END), 0) as total_belum_lunas,
            COALESCE(SUM(CASE WHEN status = 'isolir' THEN 1 ELSE 0 END), 0) as total_blokir,
            COALESCE(SUM(CASE WHEN kategori_pelanggan = 'PSB' THEN 1 ELSE 0 END), 0) as total_psb_invoices,
            COALESCE(SUM(CASE WHEN kategori_pelanggan = 'PSB' AND status = 'lunas' THEN total_dibayar ELSE (CASE WHEN kategori_pelanggan = 'PSB' THEN total_tagihan ELSE 0 END) END), 0) as nominal_psb,
            COALESCE(SUM(CASE WHEN kategori_pelanggan = 'PSB' THEN biaya_pasang ELSE 0 END), 0) as nominal_biaya_pasang,
            COALESCE(SUM(CASE WHEN kategori_pelanggan = 'PSB' THEN harga_paket ELSE 0 END), 0) as nominal_paket_psb,
            COALESCE(SUM(CASE WHEN kategori_pelanggan != 'PSB' OR kategori_pelanggan IS NULL THEN total_tagihan ELSE 0 END), 0) as nominal_bulanan,
            COALESCE(SUM(CASE WHEN status = 'lunas' AND (metode_pembayaran LIKE 'TRANSFER%' OR metode_pembayaran LIKE 'TF%') THEN total_dibayar ELSE 0 END), 0) as nominal_tf,
            COALESCE(SUM(CASE WHEN status = 'lunas' AND (metode_pembayaran LIKE 'TRANSFER%' OR metode_pembayaran LIKE 'TF%') THEN 1 ELSE 0 END), 0) as count_tf,
            COALESCE(SUM(CASE WHEN status = 'lunas' AND metode_pembayaran = 'PIHAK_2' THEN total_dibayar ELSE 0 END), 0) as nominal_pihak_2,
            COALESCE(SUM(CASE WHEN status = 'lunas' AND metode_pembayaran = 'PIHAK_2' THEN 1 ELSE 0 END), 0) as count_pihak_2,
            COALESCE(SUM(CASE WHEN status = 'lunas' AND metode_pembayaran = 'PIHAK_3' THEN total_dibayar ELSE 0 END), 0) as nominal_pihak_3,
            COALESCE(SUM(CASE WHEN status = 'lunas' AND metode_pembayaran = 'PIHAK_3' THEN 1 ELSE 0 END), 0) as count_pihak_3,
            COALESCE(SUM(CASE WHEN status = 'lunas' AND metode_pembayaran = 'CASH' THEN total_dibayar ELSE 0 END), 0) as nominal_cash,
            COALESCE(SUM(CASE WHEN status = 'lunas' AND metode_pembayaran = 'CASH' THEN 1 ELSE 0 END), 0) as count_cash
        ")->first();

        $totalBlokir = (int) ($stats->total_blokir ?? 0);

        return [
            'nominal_belum_dibayar' => (float) ($stats->nominal_belum_dibayar ?? 0),
            'nominal_lunas'         => (float) ($stats->nominal_lunas ?? 0),
            'total_invoices'        => (int) ($stats->total_invoices ?? 0),
            'total_lunas'           => (int) ($stats->total_lunas ?? 0),
            'total_belum_lunas'     => (int) ($stats->total_belum_lunas ?? 0),
            'total_blokir'          => $totalBlokir,
            'total_psb_invoices'    => (int) ($stats->total_psb_invoices ?? 0),
            'nominal_psb'           => (float) ($stats->nominal_psb ?? 0),
            'nominal_biaya_pasang'  => (float) ($stats->nominal_biaya_pasang ?? 0),
            'nominal_paket_psb'     => (float) ($stats->nominal_paket_psb ?? 0),
            'nominal_bulanan'       => (float) ($stats->nominal_bulanan ?? 0),
            'nominal_tf'            => (float) ($stats->nominal_tf ?? 0),
            'count_tf'              => (int) ($stats->count_tf ?? 0),
            'nominal_pihak_2'       => (float) ($stats->nominal_pihak_2 ?? 0),
            'count_pihak_2'         => (int) ($stats->count_pihak_2 ?? 0),
            'nominal_pihak_3'       => (float) ($stats->nominal_pihak_3 ?? 0),
            'count_pihak_3'         => (int) ($stats->count_pihak_3 ?? 0),
            'nominal_cash'          => (float) ($stats->nominal_cash ?? 0),
            'count_cash'            => (int) ($stats->count_cash ?? 0),
        ];
    }

    /**
     * Resolve actual due date (Tanggal Jatuh Tempo) according to Column I in sheet / installation date.
     */
    public static function resolveJatuhTempoDate(int $tahun, int $bulan, $sheet = null, $pelMatch = null): string
    {
        $raw = [];
        if ($sheet) {
            $raw = is_array($sheet->raw_data) ? $sheet->raw_data : (json_decode($sheet->raw_data ?? '[]', true) ?: []);
        }

        // 1. Primary: Check Column I (raw[8]) or field tanggal_jatuh_tempo
        $colI = trim((string)($raw[8] ?? ($sheet?->tanggal_jatuh_tempo ?? ($pelMatch?->tanggal_jatuh_tempo ?? ''))));

        $day = null;
        if (is_numeric($colI)) {
            $val = (int)$colI;
            if ($val >= 1 && $val <= 31) {
                $day = $val;
            }
        } elseif (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.]\d{2,4}/', $colI, $m)) {
            $val = (int)$m[1];
            if ($val >= 1 && $val <= 31) {
                $day = $val;
            }
        }

        // 2. Secondary: Check Tanggal Pemasangan / Instalasi (raw[28] or tanggal_instalasi or raw[0] timestamp)
        if (!$day && $sheet) {
            $tglInstalasi = trim((string)($sheet->tanggal_instalasi ?? ($raw[28] ?? '')));
            if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.]\d{2,4}/', $tglInstalasi, $m)) {
                $val = (int)$m[1];
                if ($val >= 1 && $val <= 31) {
                    $day = $val;
                }
            }
        }

        if (!$day && $sheet) {
            $timestamp = trim((string)($raw[0] ?? ''));
            if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.]\d{2,4}/', $timestamp, $m)) {
                $val = (int)$m[1];
                if ($val >= 1 && $val <= 31) {
                    $day = $val;
                }
            }
        }

        // 3. Fallback: Default to day 20 if no info found
        if (!$day) {
            $day = 20;
        }

        $daysInMonth = Carbon::createFromDate($tahun, $bulan, 1)->daysInMonth;
        $clampedDay = min($day, $daysInMonth);

        return Carbon::createFromDate($tahun, $bulan, $clampedDay)->format('Y-m-d');
    }
}
