<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerPackageRequest;
use App\Models\DataSheet;
use App\Models\Invoice;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\WarehouseRequest;
use App\Services\ActivityLogService;
use App\Services\InvoiceService;
use App\Services\MikrotikService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    protected MikrotikService $mikrotik;
    protected InvoiceService $invoiceService;

    public function __construct(MikrotikService $mikrotik, InvoiceService $invoiceService)
    {
        $this->mikrotik = $mikrotik;
        $this->invoiceService = $invoiceService;
    }

    /**
     * Finance & Billing Management Dashboard.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $setting = Setting::getSetting();
        $tab = $request->query('tab', 'package_requests');
        $search = trim($request->query('q', ''));

        $bulan = (int) $request->input('bulan', now()->month);
        $tahun = (int) $request->input('tahun', now()->year);
        $tglMulai = trim((string) $request->input('tgl_mulai', ''));
        $tglAkhir = trim((string) $request->input('tgl_akhir', ''));
        $sortBy = trim((string) $request->input('by', 'tanggal'));
        $sortOrder = strtolower(trim((string) $request->input('order', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $perPage = (int) $request->input('per_page', 15);
        if ($perPage < 5 || $perPage > 100) $perPage = 15;

        // 1. Upgrade / Downgrade Requests Query (Filtered by date range or month & year)
        $packageReqQuery = CustomerPackageRequest::with(['requester', 'approver']);
        if ($tglMulai && $tglAkhir) {
            $packageReqQuery->whereBetween(DB::raw('DATE(created_at)'), [$tglMulai, $tglAkhir]);
        } elseif ($tglMulai) {
            $packageReqQuery->where(DB::raw('DATE(created_at)'), '>=', $tglMulai);
        } elseif ($tglAkhir) {
            $packageReqQuery->where(DB::raw('DATE(created_at)'), '<=', $tglAkhir);
        } else {
            if ($bulan > 0) $packageReqQuery->whereMonth('created_at', $bulan);
            if ($tahun > 0) $packageReqQuery->whereYear('created_at', $tahun);
        }

        if ($search) {
            $packageReqQuery->where(function ($q) use ($search) {
                $q->where('nomor_pengajuan', 'like', "%{$search}%")
                  ->orWhere('pelanggan_username', 'like', "%{$search}%")
                  ->orWhere('pelanggan_nama', 'like', "%{$search}%")
                  ->orWhere('id_customer', 'like', "%{$search}%");
            });
        }
        if ($sortBy === 'nomor') {
            $packageReqQuery->orderBy('nomor_pengajuan', $sortOrder);
        } elseif ($sortBy === 'nama') {
            $packageReqQuery->orderBy('pelanggan_nama', $sortOrder);
        } else {
            $packageReqQuery->orderBy('created_at', $sortOrder);
        }
        $packageRequests = $packageReqQuery->paginate($perPage, ['*'], 'pkg_page')->withQueryString();

        // 2. Warehouse Procurement Requests (Menunggu Approval Belanja)
        $procurementQuery = WarehouseRequest::with(['user', 'financeApprover', 'items.item'])
            ->where('tipe_request', 'restock_procurement');
        if ($tglMulai && $tglAkhir) {
            $procurementQuery->whereBetween(DB::raw('DATE(created_at)'), [$tglMulai, $tglAkhir]);
        } elseif ($tglMulai) {
            $procurementQuery->where(DB::raw('DATE(created_at)'), '>=', $tglMulai);
        } elseif ($tglAkhir) {
            $procurementQuery->where(DB::raw('DATE(created_at)'), '<=', $tglAkhir);
        } else {
            if ($bulan > 0) $procurementQuery->whereMonth('created_at', $bulan);
            if ($tahun > 0) $procurementQuery->whereYear('created_at', $tahun);
        }

        if ($search) {
            $procurementQuery->where('nomor_request', 'like', "%{$search}%")
                ->orWhere('alasan', 'like', "%{$search}%");
        }
        if ($sortBy === 'nomor') {
            $procurementQuery->orderBy('nomor_request', $sortOrder);
        } else {
            $procurementQuery->orderBy('created_at', $sortOrder);
        }
        $procurementRequests = $procurementQuery->paginate($perPage, ['*'], 'proc_page')->withQueryString();

        // 3. PSB Payment Validation Queue
        $psbPaymentQuery = Ticket::with(['creator', 'paymentVerifier', 'technician'])
            ->where('type', 'psb')
            ->whereIn('payment_status', ['pending_cash_settlement', 'pending_transfer_verification', 'approved', 'rejected']);
        if ($tglMulai && $tglAkhir) {
            $psbPaymentQuery->whereBetween(DB::raw('DATE(created_at)'), [$tglMulai, $tglAkhir]);
        } elseif ($tglMulai) {
            $psbPaymentQuery->where(DB::raw('DATE(created_at)'), '>=', $tglMulai);
        } elseif ($tglAkhir) {
            $psbPaymentQuery->where(DB::raw('DATE(created_at)'), '<=', $tglAkhir);
        } else {
            if ($bulan > 0) $psbPaymentQuery->whereMonth('created_at', $bulan);
            if ($tahun > 0) $psbPaymentQuery->whereYear('created_at', $tahun);
        }

        if ($search) {
            $psbPaymentQuery->where(function ($q) use ($search) {
                $q->where('ticket_number', 'like', "%{$search}%")
                  ->orWhere('pelanggan_username', 'like', "%{$search}%")
                  ->orWhere('pelanggan_nama', 'like', "%{$search}%")
                  ->orWhere('id_customer', 'like', "%{$search}%")
                  ->orWhere('vlan', 'like', "%{$search}%")
                  ->orWhere('nama_marketing', 'like', "%{$search}%");
            });
        }
        if ($sortBy === 'nomor') {
            $psbPaymentQuery->orderBy('ticket_number', $sortOrder);
        } elseif ($sortBy === 'nama') {
            $psbPaymentQuery->orderBy('pelanggan_nama', $sortOrder);
        } else {
            $psbPaymentQuery->orderBy('created_at', $sortOrder);
        }
        $psbPayments = $psbPaymentQuery->paginate($perPage, ['*'], 'psb_page')->withQueryString();

        // Dynamic KPI Calculation according to selected Date Range or Bulan & Tahun
        $psbApprovedQuery = Ticket::where('type', 'psb')->where('payment_status', 'approved');
        $invoiceLunasQuery = Invoice::where('status', 'lunas');
        $belanjaGudangQuery = \App\Models\WarehouseRequestItem::join('warehouse_requests', 'warehouse_requests.id', '=', 'warehouse_request_items.warehouse_request_id')
            ->join('warehouse_items', 'warehouse_items.id', '=', 'warehouse_request_items.warehouse_item_id')
            ->where('warehouse_requests.tipe_request', 'restock_procurement')
            ->where('warehouse_requests.status', 'approved');

        if ($tglMulai && $tglAkhir) {
            $psbApprovedQuery->whereBetween(DB::raw('DATE(created_at)'), [$tglMulai, $tglAkhir]);
            $invoiceLunasQuery->whereBetween('tanggal_invoice', [$tglMulai, $tglAkhir]);
            $belanjaGudangQuery->whereBetween(DB::raw('DATE(warehouse_requests.created_at)'), [$tglMulai, $tglAkhir]);
        } elseif ($tglMulai) {
            $psbApprovedQuery->where(DB::raw('DATE(created_at)'), '>=', $tglMulai);
            $invoiceLunasQuery->where('tanggal_invoice', '>=', $tglMulai);
            $belanjaGudangQuery->where(DB::raw('DATE(warehouse_requests.created_at)'), '>=', $tglMulai);
        } elseif ($tglAkhir) {
            $psbApprovedQuery->where(DB::raw('DATE(created_at)'), '<=', $tglAkhir);
            $invoiceLunasQuery->where('tanggal_invoice', '<=', $tglAkhir);
            $belanjaGudangQuery->where(DB::raw('DATE(warehouse_requests.created_at)'), '<=', $tglAkhir);
        } else {
            if ($bulan > 0) {
                $psbApprovedQuery->whereMonth('created_at', $bulan);
                $invoiceLunasQuery->where('periode_bulan', $bulan);
                $belanjaGudangQuery->whereMonth('warehouse_requests.created_at', $bulan);
            }
            if ($tahun > 0) {
                $psbApprovedQuery->whereYear('created_at', $tahun);
                $invoiceLunasQuery->where('periode_tahun', $tahun);
                $belanjaGudangQuery->whereYear('warehouse_requests.created_at', $tahun);
            }
        }

        $totalBiayaPasangPsb = (float) $psbApprovedQuery->sum('biaya_pasang');
        $totalBiayaPaketPsb = (float) $psbApprovedQuery->sum('harga_paket');
        $totalPemasukanPsb = $totalBiayaPasangPsb + $totalBiayaPaketPsb;
        $totalInvoiceLunas = (float) $invoiceLunasQuery->sum('total_dibayar');
        $totalBelanjaGudang = (float) $belanjaGudangQuery->sum(DB::raw('COALESCE(warehouse_request_items.jumlah_disetujui, warehouse_request_items.jumlah_diminta, 0) * COALESCE(warehouse_items.harga_estimasi, 0)'));

        // Total Pendapatan Bersih = Total Pemasukan Lunas (PSB Pasang + PSB Paket + Invoice Lunas) - Belanja Restock Gudang
        $totalPendapatanKotor = $totalPemasukanPsb + $totalInvoiceLunas;
        $totalPendapatanBersih = max(0, $totalPendapatanKotor - $totalBelanjaGudang);

        $counts = [
            'pending_pkg'             => (clone $packageReqQuery)->where('status', 'pending_finance')->count(),
            'approved_pkg_this_month' => CustomerPackageRequest::where('status', 'approved')->whereMonth('approved_at', $bulan)->whereYear('approved_at', $tahun)->count(),
            'pending_procurement'     => (clone $procurementQuery)->where('status', 'pending_finance')->count(),
            'pending_psb_payments'    => (clone $psbPaymentQuery)->whereIn('payment_status', ['pending_cash_settlement', 'pending_transfer_verification'])->count(),
            'total_invoices_month'    => Invoice::where('periode_bulan', $bulan)->where('periode_tahun', $tahun)->count(),
            'total_omset_lunas'       => $totalInvoiceLunas,
            'total_biaya_pasang_psb'  => $totalBiayaPasangPsb,
            'total_biaya_paket_psb'   => $totalBiayaPaketPsb,
            'total_pemasukan_psb'     => $totalPemasukanPsb,
            'total_belanja_gudang'    => $totalBelanjaGudang,
            'total_pemasukan_kotor'   => $totalPendapatanKotor,
            'total_pendapatan_bersih' => $totalPendapatanBersih,
        ];

        $availablePakets = Paket::where('is_active', true)->orderBy('tarif_bulanan')->get();

        // Comprehensive Customer Options from both DataSheet & Pelanggan
        $sheetList = DataSheet::orderBy('nama_pelanggan')->get(['username_pppoe', 'nama_pelanggan', 'paket', 'raw_data']);
        $pelangganList = Pelanggan::orderBy('nama')->get(['id_customer', 'nama', 'username', 'paket']);

        $customerOptions = collect();
        $seen = [];

        foreach ($sheetList as $s) {
            $u = trim((string)$s->username_pppoe);
            if (!$u || isset($seen[strtolower($u)])) continue;
            $seen[strtolower($u)] = true;
            $raw = is_array($s->raw_data) ? $s->raw_data : (json_decode($s->raw_data, true) ?: []);
            $idCust = $raw['id_customer'] ?? null;
            if (!empty($idCust) && $idCust === $s->nik_ktp) {
                $idCust = null;
            }
            $customerOptions->push((object)[
                'username'    => $u,
                'id_customer' => $idCust,
                'nama'        => $s->nama_pelanggan ?: $u,
                'paket'       => $s->paket ?: '-',
            ]);
        }

        foreach ($pelangganList as $p) {
            $u = trim((string)$p->username);
            if (!$u || isset($seen[strtolower($u)])) continue;
            $seen[strtolower($u)] = true;
            $customerOptions->push((object)[
                'username'    => $u,
                'id_customer' => $p->id_customer,
                'nama'        => $p->nama ?: $u,
                'paket'       => $p->paket ?: '-',
            ]);
        }

        return view('finance.index', compact(
            'tab', 'search', 'bulan', 'tahun', 'tglMulai', 'tglAkhir', 'sortBy', 'sortOrder',
            'perPage', 'packageRequests', 'procurementRequests', 'psbPayments', 'counts',
            'setting', 'availablePakets', 'customerOptions'
        ));
    }

    /**
     * Submit Upgrade / Downgrade Request (CS / Finance / Admin).
     */
    public function storePackageRequest(Request $request): RedirectResponse
    {
        $request->validate([
            'pelanggan_username' => 'required|string',
            'paket_baru_id'      => 'required|exists:pakets,id',
            'alasan'             => 'nullable|string|max:500',
        ]);

        $user = Auth::user();
        $username = trim($request->input('pelanggan_username'));
        $newPaket = Paket::findOrFail($request->input('paket_baru_id'));

        // Find customer in database or mikrotik
        $pelanggan = Pelanggan::where('username', $username)->first();
        $currentPaketName = $pelanggan?->paket ?? 'Standard';
        $currentPaket = Paket::where('nama_paket', $currentPaketName)->orWhere('mikrotik_profile', $currentPaketName)->first();
        $oldPrice = $currentPaket?->tarif_bulanan ?? 0;
        $newPrice = $newPaket->tarif_bulanan ?? 0;
        $customerName = $pelanggan?->nama ?? $username;
        $customerId = $pelanggan?->id_customer;

        $nomorPengajuan = 'PKG-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));

        $isDirectApproval = $user?->isSuperAdmin() || $user?->role === 'admin' || $user?->role === 'finance';

        DB::beginTransaction();
        try {
            $pkgReq = CustomerPackageRequest::create([
                'nomor_pengajuan'    => $nomorPengajuan,
                'id_customer'        => $customerId,
                'pelanggan_username' => $username,
                'pelanggan_nama'     => $customerName,
                'paket_lama'         => $currentPaketName,
                'paket_baru'         => $newPaket->nama_paket,
                'harga_lama'         => $oldPrice,
                'harga_baru'         => $newPrice,
                'selisih_tarif'      => $newPrice - $oldPrice,
                'alasan'             => $request->input('alasan'),
                'status'             => $isDirectApproval ? 'approved' : 'pending_finance',
                'requested_by'       => $user->id,
                'approved_by'        => $isDirectApproval ? $user->id : null,
                'approved_at'        => $isDirectApproval ? now() : null,
                'catatan_finance'    => $isDirectApproval ? 'Langsung disetujui oleh ' . $user->nama : null,
            ]);

            if ($isDirectApproval) {
                // Execute change on MikroTik directly
                $profileTarget = $newPaket->mikrotik_profile ?: $newPaket->nama_paket;
                $mtResult = $this->mikrotik->updatePppoeProfile($username, $profileTarget);

                // Update database records
                Pelanggan::where('username', $username)->update(['paket' => $newPaket->nama_paket]);
                DataSheet::where('username_pppoe', $username)->update([
                    'paket'       => $newPaket->nama_paket,
                    'harga_paket' => $newPrice,
                ]);

                ActivityLogService::log(
                    'SUCCESS',
                    'Upgrade/Downgrade Paket Langsung',
                    "Mengubah paket {$username} dari {$currentPaketName} ke {$newPaket->nama_paket} (MikroTik: " . ($mtResult['message'] ?? 'OK') . ")",
                    $user->username ?? 'Finance'
                );
            } else {
                ActivityLogService::log(
                    'INFO',
                    'Pengajuan Upgrade/Downgrade Paket',
                    "CS {$user->nama} mengajukan perubahan paket untuk {$username} ke {$newPaket->nama_paket} ({$nomorPengajuan})",
                    $user->username ?? 'CS'
                );
            }

            DB::commit();

            if ($isDirectApproval) {
                return redirect()->back()->with('sukses', "⚡ Paket pelanggan {$username} berhasil diubah ke {$newPaket->nama_paket} dan terhubung langsung ke MikroTik!");
            }

            return redirect()->back()->with('sukses', "📋 Pengajuan perubahan paket {$nomorPengajuan} ({$username}) berhasil dikirim ke antrean Finance Billing!");
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Failed to process package change request: " . $e->getMessage());
            return redirect()->back()->with('error', "Gagal memproses pengajuan paket: " . $e->getMessage());
        }
    }

    /**
     * Finance Approves or Rejects Package Change Request.
     */
    public function approvePackageRequest(Request $request, int $id): RedirectResponse
    {
        $pkgReq = CustomerPackageRequest::findOrFail($id);
        $user = Auth::user();
        $action = $request->input('action', 'approve'); // approve / reject
        $catatan = trim((string)$request->input('catatan_finance', ''));

        if ($action === 'approve') {
            $newPaket = Paket::where('nama_paket', $pkgReq->paket_baru)->orWhere('mikrotik_profile', $pkgReq->paket_baru)->first();
            $profileTarget = $newPaket?->mikrotik_profile ?: $pkgReq->paket_baru;

            // Execute change on MikroTik
            $mtResult = $this->mikrotik->updatePppoeProfile($pkgReq->pelanggan_username, $profileTarget);

            // Update database records
            Pelanggan::where('username', $pkgReq->pelanggan_username)->update(['paket' => $pkgReq->paket_baru]);
            DataSheet::where('username_pppoe', $pkgReq->pelanggan_username)->update([
                'paket'       => $pkgReq->paket_baru,
                'harga_paket' => $pkgReq->harga_baru,
            ]);

            $pkgReq->update([
                'status'          => 'approved',
                'approved_by'     => $user->id,
                'approved_at'     => now(),
                'catatan_finance' => $catatan ?: 'Disetujui oleh Finance Billing & profil MikroTik diperbarui.',
            ]);

            ActivityLogService::log(
                'SUCCESS',
                'Approval Upgrade/Downgrade Paket',
                "Finance {$user->nama} menyetujui pengajuan {$pkgReq->nomor_pengajuan} ({$pkgReq->pelanggan_username} -> {$pkgReq->paket_baru})",
                $user->username ?? 'Finance'
            );

            return redirect()->back()->with('sukses', "✅ Pengajuan {$pkgReq->nomor_pengajuan} disetujui! Paket {$pkgReq->pelanggan_username} berhasil diperbarui di MikroTik dan Database.");
        } else {
            $pkgReq->update([
                'status'          => 'rejected',
                'approved_by'     => $user->id,
                'approved_at'     => now(),
                'catatan_finance' => $catatan ?: 'Ditolak oleh Finance Billing.',
            ]);

            ActivityLogService::log(
                'WARNING',
                'Penolakan Upgrade/Downgrade Paket',
                "Finance {$user->nama} menolak pengajuan {$pkgReq->nomor_pengajuan} ({$pkgReq->pelanggan_username})",
                $user->username ?? 'Finance'
            );

            return redirect()->back()->with('sukses', "❌ Pengajuan {$pkgReq->nomor_pengajuan} telah ditolak.");
        }
    }

    /**
     * Finance validates PSB payment after installation.
     */
    public function actionPsbPayment(Request $request, int $id): RedirectResponse
    {
        $ticket = Ticket::where('type', 'psb')->findOrFail($id);
        $user = Auth::user();
        $action = $request->input('action', 'approve');
        $catatan = trim((string) $request->input('payment_notes', ''));

        if ($action === 'approve') {
            $ticket->update([
                'payment_status' => 'approved',
                'payment_notes' => $catatan ?: 'Pembayaran PSB telah divalidasi Finance & Billing.',
                'payment_verified_by' => $user->id,
                'payment_verified_at' => now(),
            ]);

            // Auto-generate official PSB Installation Invoice in Data Invoice
            try {
                $existingInv = Invoice::where('ticket_id', $ticket->id)->first();
                if (!$existingInv) {
                    $biayaPasang = (float) ($ticket->biaya_pasang ?? 0);
                    $hargaPaket = (float) ($ticket->harga_paket ?? 0);
                    $potongan = (float) ($ticket->potongan ?? 0);
                    $totalTagihan = max(0, $biayaPasang + $hargaPaket - $potongan);

                    $seq = Invoice::where('periode_bulan', now()->month)->where('periode_tahun', now()->year)->count() + 1;
                    $nomorInvoice = 'INV-PSB-' . date('Ym') . '-' . sprintf('%04d', $seq);
                    while (Invoice::where('nomor_invoice', $nomorInvoice)->exists()) {
                        $seq++;
                        $nomorInvoice = 'INV-PSB-' . date('Ym') . '-' . sprintf('%04d', $seq);
                    }

                    Invoice::create([
                        'nomor_invoice'       => $nomorInvoice,
                        'ticket_id'           => $ticket->id,
                        'kategori_pelanggan'  => 'PSB',
                        'id_customer'         => $ticket->id_customer,
                        'pelanggan_username'  => $ticket->pelanggan_username,
                        'pelanggan_nama'      => $ticket->pelanggan_nama,
                        'pelanggan_telepon'   => $ticket->pelanggan_telepon,
                        'pelanggan_alamat'    => $ticket->alamat,
                        'marketing_pic'       => $ticket->nama_marketing ?: 'Marketing EONET',
                        'teknisi_pic'         => $ticket->technician?->nama ?: 'Teknisi EONET',
                        'paket_nama'          => $ticket->paket_nama ?: 'PAKET PASANG BARU',
                        'harga_paket'         => $hargaPaket,
                        'biaya_pasang'        => $biayaPasang,
                        'tax'                 => 0,
                        'potongan'            => $potongan,
                        'total_tagihan'       => $totalTagihan,
                        'total_dibayar'       => $totalTagihan,
                        'sisa_piutang'        => 0,
                        'periode_bulan'       => now()->month,
                        'periode_tahun'       => now()->year,
                        'tanggal_invoice'     => now()->toDateString(),
                        'tanggal_jatuh_tempo' => now()->toDateString(),
                        'status'              => 'lunas',
                        'metode_pembayaran'   => $ticket->metode_pembayaran ?: 'CASH',
                        'tanggal_bayar'       => now(),
                        'bukti_bayar'         => $ticket->bukti_bayar,
                        'keterangan'          => "Biaya Pemasangan & Paket PSB #{$ticket->ticket_number} (Pasang: Rp " . number_format($biayaPasang, 0, ',', '.') . " + Paket: Rp " . number_format($hargaPaket, 0, ',', '.') . ")",
                        'created_by'          => $user->id,
                        'verified_by'         => $user->id,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning("Gagal auto-generate Invoice PSB saat Approval: {$e->getMessage()}");
            }

            // Auto sync to DataSheet & Google Sheet webhook
            try {
                DataSheet::syncFromTicket($ticket);
                \App\Services\GoogleSheetSyncService::syncTicketToGoogleSheetAsync($ticket);
            } catch (\Throwable $e) {
                Log::warning("Gagal auto-sync Google Sheet saat Finance Approval: {$e->getMessage()}");
            }

            ActivityLogService::log(
                'SUCCESS',
                'Approval Pembayaran PSB',
                "Finance {$user->nama} menyetujui pembayaran PSB {$ticket->ticket_number} ({$ticket->pelanggan_username}) & menerbitkan Invoice PSB",
                $user->username ?? 'Finance'
            );

            return redirect()->back()->with('sukses', "✅ Pembayaran PSB {$ticket->ticket_number} berhasil divalidasi & Invoice Resmi PSB berhasil diterbitkan di Data Invoice!");
        }

        $ticket->update([
            'payment_status' => 'rejected',
            'payment_notes' => $catatan ?: 'Perlu tindak lanjut atau koreksi bukti/setoran pembayaran PSB.',
            'payment_verified_by' => $user->id,
            'payment_verified_at' => now(),
        ]);

        ActivityLogService::log(
            'WARNING',
            'Tindak Lanjut Pembayaran PSB',
            "Finance {$user->nama} meminta tindak lanjut pembayaran PSB {$ticket->ticket_number} ({$ticket->pelanggan_username})",
            $user->username ?? 'Finance'
        );

        return redirect()->back()->with('sukses', "Pembayaran PSB {$ticket->ticket_number} ditandai perlu tindak lanjut.");
    }

    /**
     * Delete customer billing record (Superadmin / Admin).
     */
    public function destroyPelanggan(int $id): RedirectResponse
    {
        $user = Auth::user();
        if (!$user?->isSuperAdmin() && $user?->role !== 'admin' && !$user?->hasPermission('pelanggan_delete')) {
            return redirect()->back()->with('error', 'Anda tidak memiliki hak akses untuk menghapus data pelanggan.');
        }

        $pelanggan = Pelanggan::findOrFail($id);
        $nama = $pelanggan->nama;
        $username = $pelanggan->username;

        $pelanggan->delete();

        Cache::forget('finance_dashboard_counts');

        ActivityLogService::log(
            'WARNING',
            'Hapus Billing Pelanggan',
            "User {$user->nama} menghapus data billing pelanggan {$nama} ({$username})",
            $user->username ?? 'Finance'
        );

        return redirect()->back()->with('sukses', "🗑️ Data billing pelanggan '{$nama}' ({$username}) berhasil dihapus.");
    }

    /**
     * Delete package change request (Superadmin / Admin).
     */
    public function destroyPackageRequest(int $id): RedirectResponse
    {
        $user = Auth::user();
        if (!$user?->isSuperAdmin() && $user?->role !== 'admin' && !$user?->hasPermission('finance_edit')) {
            return redirect()->back()->with('error', 'Anda tidak memiliki hak akses untuk menghapus riwayat pengajuan.');
        }

        $req = CustomerPackageRequest::findOrFail($id);
        $nomor = $req->nomor_pengajuan;

        $req->delete();

        Cache::forget('finance_dashboard_counts');

        ActivityLogService::log(
            'WARNING',
            'Hapus Pengajuan Paket',
            "User {$user->nama} menghapus data pengajuan paket {$nomor}",
            $user->username ?? 'Finance'
        );

        return redirect()->back()->with('sukses', "🗑️ Riwayat pengajuan paket '{$nomor}' berhasil dihapus.");
    }

    /**
     * Delete warehouse procurement request (Superadmin / Admin).
     */
    public function destroyProcurement(int $id): RedirectResponse
    {
        $user = Auth::user();
        if (!$user?->isSuperAdmin() && $user?->role !== 'admin') {
            return redirect()->back()->with('error', 'Anda tidak memiliki hak akses untuk menghapus pengajuan belanja.');
        }

        $pReq = WarehouseRequest::findOrFail($id);
        $nomor = $pReq->nomor_request;

        $pReq->items()->delete();
        $pReq->delete();

        Cache::forget('finance_dashboard_counts');

        ActivityLogService::log(
            'WARNING',
            'Hapus Pengajuan Belanja',
            "User {$user->nama} menghapus pengajuan belanja gudang {$nomor}",
            $user->username ?? 'Finance'
        );

        return redirect()->back()->with('sukses', "🗑️ Pengajuan belanja gudang '{$nomor}' berhasil dihapus.");
    }

    // =========================================================================
    // DATA INVOICE SUB-MENU & MANAGEMENT
    // =========================================================================

    /**
     * Data Invoice Management View (Matching Image 2).
     */
    public function invoiceIndex(Request $request)
    {
        $bulan = $request->has('bulan') ? (int) $request->input('bulan') : now()->month;
        $tahun = $request->has('tahun') ? (int) $request->input('tahun') : now()->year;
        $tglMulai = trim((string) $request->input('tgl_mulai', ''));
        $tglAkhir = trim((string) $request->input('tgl_akhir', ''));
        $status = trim((string) $request->input('status', ''));
        $kategori = trim((string) $request->input('kategori', ''));
        $metode = trim((string) $request->input('metode', ''));
        $search = trim((string) $request->input('q', ''));
        $marketing = trim((string) $request->input('marketing', ''));
        $teknisi = trim((string) $request->input('teknisi', ''));
        $sortBy = trim((string) $request->input('by', 'tanggal'));
        $sortOrder = strtolower(trim((string) $request->input('order', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $perPage = (int) $request->input('per_page', 10);
        if ($perPage < 5 || $perPage > 500) $perPage = 10;

        // Smart Date Column Detection: if date_by is set OR if sorting by jatuh_tempo, filter on tanggal_jatuh_tempo
        $dateBy = trim((string) $request->input('date_by', ''));
        if (empty($dateBy)) {
            $dateBy = ($sortBy === 'jatuh_tempo') ? 'jatuh_tempo' : 'invoice';
        }

        $dateColumn = ($dateBy === 'jatuh_tempo') ? 'tanggal_jatuh_tempo' : (($dateBy === 'bayar') ? 'tanggal_bayar' : 'tanggal_invoice');

        $query = Invoice::query();

        // Date Range / Period Filtering with precise time boundary (00:00:00 to 23:59:59)
        if ($tglMulai && $tglAkhir) {
            $startBoundary = substr($tglMulai, 0, 10) . ' 00:00:00';
            $endBoundary = substr($tglAkhir, 0, 10) . ' 23:59:59';
            $query->whereBetween($dateColumn, [$startBoundary, $endBoundary]);
        } elseif ($tglMulai) {
            $startBoundary = substr($tglMulai, 0, 10) . ' 00:00:00';
            $query->where($dateColumn, '>=', $startBoundary);
        } elseif ($tglAkhir) {
            $endBoundary = substr($tglAkhir, 0, 10) . ' 23:59:59';
            $query->where($dateColumn, '<=', $endBoundary);
        } else {
            if ($bulan > 0) {
                if ($dateColumn === 'tanggal_jatuh_tempo') {
                    $query->whereMonth('tanggal_jatuh_tempo', $bulan);
                } else {
                    $query->where('periode_bulan', $bulan);
                }
            }
            if ($tahun > 0) {
                if ($dateColumn === 'tanggal_jatuh_tempo') {
                    $query->whereYear('tanggal_jatuh_tempo', $tahun);
                } else {
                    $query->where('periode_tahun', $tahun);
                }
            }
        }

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }
        if ($kategori && $kategori !== 'all') {
            if ($kategori === 'PSB') {
                $query->where('kategori_pelanggan', 'PSB');
            } elseif ($kategori === 'BULANAN') {
                $query->where(function ($q) {
                    $q->where('kategori_pelanggan', '!=', 'PSB')
                      ->orWhereNull('kategori_pelanggan');
                });
            } else {
                $query->where('kategori_pelanggan', $kategori);
            }
        }
        if ($metode && $metode !== 'all') {
            $query->where('metode_pembayaran', $metode);
        }
        if ($marketing && $marketing !== 'all') {
            $query->where('marketing_pic', 'like', "%{$marketing}%");
        }
        if ($teknisi && $teknisi !== 'all') {
            $query->where('teknisi_pic', 'like', "%{$teknisi}%");
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nomor_invoice', 'like', "%{$search}%")
                  ->orWhere('id_customer', 'like', "%{$search}%")
                  ->orWhere('pelanggan_nama', 'like', "%{$search}%")
                  ->orWhere('pelanggan_username', 'like', "%{$search}%")
                  ->orWhere('pelanggan_telepon', 'like', "%{$search}%")
                  ->orWhere('paket_nama', 'like', "%{$search}%");
            });
        }

        // Sorting / Order by selection
        switch ($sortBy) {
            case 'nomor':
                $query->orderBy('nomor_invoice', $sortOrder);
                break;
            case 'nama':
                $query->orderBy('pelanggan_nama', $sortOrder);
                break;
            case 'total':
            case 'nominal':
                $query->orderBy('total_tagihan', $sortOrder);
                break;
            case 'jatuh_tempo':
                $query->orderBy('tanggal_jatuh_tempo', $sortOrder);
                break;
            case 'status':
                $query->orderBy('status', $sortOrder);
                break;
            case 'tanggal':
            default:
                $query->orderBy('tanggal_invoice', $sortOrder)->orderBy('id', $sortOrder);
                break;
        }

        $invoices = $query->paginate($perPage)->withQueryString();

        // Calculate KPI summary stats for the selected period / date range using active date column
        $kpis = $this->invoiceService->getInvoiceKpis(
            $bulan > 0 ? $bulan : null,
            $tahun > 0 ? $tahun : null,
            $tglMulai ?: null,
            $tglAkhir ?: null,
            $dateColumn
        );

        $pakets = Paket::where('is_active', true)->orderBy('tarif_bulanan')->get();

        $customerOptions = \Illuminate\Support\Facades\Cache::remember('finance_invoice_customer_options_v1', 120, function () {
            $sheetList = DataSheet::orderBy('nama_pelanggan')->get(['username_pppoe', 'nama_pelanggan', 'paket', 'harga_paket', 'alamat', 'telepon', 'raw_data']);
            $pelangganList = Pelanggan::orderBy('nama')->get(['id_customer', 'nama', 'username', 'paket', 'harga_paket', 'alamat', 'telepon']);

            $options = collect();
            $seen = [];

            foreach ($sheetList as $s) {
                $u = trim((string)$s->username_pppoe);
                if (!$u || isset($seen[strtolower($u)])) continue;
                $seen[strtolower($u)] = true;
                $raw = is_array($s->raw_data) ? $s->raw_data : (json_decode($s->raw_data, true) ?: []);
                $idCust = $raw['id_customer'] ?? ($raw[1] ?? null);
                if (!empty($idCust) && $idCust === $s->nik_ktp) {
                    $idCust = null;
                }
                $options->push((object)[
                    'username'    => $u,
                    'id_customer' => $idCust,
                    'nama'        => $s->nama_pelanggan ?: $u,
                    'paket'       => $s->paket ?: 'Standard',
                    'harga'       => (float)($s->harga_paket > 0 ? $s->harga_paket : 0),
                    'alamat'      => $s->alamat ?: '',
                    'telepon'     => $s->telepon ?: '',
                ]);
            }

            foreach ($pelangganList as $p) {
                $u = trim((string)$p->username);
                if (!$u || isset($seen[strtolower($u)])) continue;
                $seen[strtolower($u)] = true;
                $options->push((object)[
                    'username'    => $u,
                    'id_customer' => $p->id_customer,
                    'nama'        => $p->nama ?: $u,
                    'paket'       => $p->paket ?: 'Standard',
                    'harga'       => (float)($p->harga_paket ?? 0),
                    'alamat'      => $p->alamat ?: '',
                    'telepon'     => $p->telepon ?: '',
                ]);
            }

            return $options;
        });

        // Unique marketing and teknisi for filter dropdowns
        $marketings = Invoice::distinct()->whereNotNull('marketing_pic')->pluck('marketing_pic');
        $teknisis = Invoice::distinct()->whereNotNull('teknisi_pic')->pluck('teknisi_pic');

        return view('finance.invoice.index', compact(
            'invoices', 'kpis', 'bulan', 'tahun', 'tglMulai', 'tglAkhir', 'status', 'kategori', 'metode', 'search',
            'marketing', 'teknisi', 'sortBy', 'sortOrder', 'perPage', 'pakets', 'customerOptions', 'marketings', 'teknisis',
            'dateBy', 'dateColumn'
        ));
    }

    /**
     * Store a single invoice manually.
     */
    public function invoiceStore(Request $request): RedirectResponse
    {
        $request->validate([
            'pelanggan_username'   => 'required|string',
            'pelanggan_nama'       => 'required|string',
            'paket_nama'           => 'required|string',
            'harga_paket'          => 'required|numeric|min:0',
            'biaya_pasang'         => 'nullable|numeric|min:0',
            'biaya_pemasangan'     => 'nullable|numeric|min:0',
            'ada_biaya_pemasangan' => 'nullable',
            'catatan_pemasangan'   => 'nullable|string',
            'periode_bulan'        => 'required|integer|min:1|max:12',
            'periode_tahun'        => 'required|integer|min:2020|max:2035',
            'keterangan'           => 'nullable|string|max:500',
        ]);

        $user = Auth::user();
        $invoice = $this->invoiceService->createSingleInvoice($request->all(), $user?->id);

        ActivityLogService::log(
            'INFO',
            'Buat Invoice Manual',
            "Membuat invoice manual {$invoice->nomor_invoice} ({$invoice->kategori_pelanggan}) untuk {$invoice->pelanggan_nama} (Rp " . number_format((float)$invoice->total_tagihan, 0, ',', '.') . ")",
            $user->username ?? 'Finance'
        );

        return redirect()->back()->with('sukses', "✅ Invoice {$invoice->nomor_invoice} untuk {$invoice->pelanggan_nama} berhasil dibuat!");
    }

    /**
     * Batch generate monthly invoices for all active customers (Awal Bulan / Tanggal 1).
     */
    public function invoiceGenerateMonthly(Request $request): RedirectResponse
    {
        $bulan = (int) $request->input('periode_bulan', now()->month);
        $tahun = (int) $request->input('periode_tahun', now()->year);
        $user = Auth::user();

        $res = $this->invoiceService->generateMonthlyInvoices($bulan, $tahun, $user?->id);

        if (!empty($res['success'])) {
            ActivityLogService::log(
                'SUCCESS',
                'Generate Invoice Bulanan',
                "Generate {$res['created_count']} invoice bulanan periode {$bulan}/{$tahun} (Total: Rp " . number_format($res['total_nominal'], 0, ',', '.') . ")",
                $user->username ?? 'Finance'
            );

            return redirect()->back()->with('sukses', "⚡ Berhasil membuat {$res['created_count']} invoice baru untuk periode {$bulan}/{$tahun}! ({$res['skipped_uninstalled']} pelanggan UNINSTALL dilewati, {$res['skipped_unpaid_backlog']} pelanggan dengan tunggakan dilewati).");
        }

        return redirect()->back()->with('error', "Gagal membuat invoice bulanan: " . ($res['message'] ?? 'Terjadi kesalahan'));
    }

    /**
     * Settle / Pay single invoice.
     */
    public function invoicePay(Request $request, int $id): RedirectResponse
    {
        $user = Auth::user();
        $data = [
            'metode_pembayaran' => $request->input('metode_pembayaran', 'CASH'),
            'nominal_bayar'     => $request->input('nominal_bayar'),
            'tanggal_bayar'     => $request->input('tanggal_bayar') ?: now(),
            'keterangan'        => $request->input('keterangan'),
        ];

        if ($request->hasFile('bukti_bayar')) {
            $path = $request->file('bukti_bayar')->store('invoices/receipts', 'public');
            $data['bukti_bayar'] = $path;
        }

        $invoice = $this->invoiceService->payInvoice($id, $data, $user?->id);

        ActivityLogService::log(
            'SUCCESS',
            'Pembayaran Invoice',
            "Pelunasan invoice {$invoice->nomor_invoice} senilai Rp " . number_format((float)$invoice->total_dibayar, 0, ',', '.') . " ({$invoice->pelanggan_nama})",
            $user->username ?? 'Finance'
        );

        return redirect()->back()->with('sukses', "✅ Pembayaran invoice {$invoice->nomor_invoice} berhasil dicatat (Status: {$invoice->status})!");
    }

    /**
     * Bulk pay multiple invoices at once.
     */
    public function invoiceBulkPay(Request $request): RedirectResponse
    {
        $ids = $request->input('invoice_ids', []);
        $metode = $request->input('bulk_metode', 'CASH');
        $user = Auth::user();

        if (empty($ids) || !is_array($ids)) {
            return redirect()->back()->with('error', 'Pilih setidaknya satu invoice untuk dibayar.');
        }

        $count = 0;
        foreach ($ids as $id) {
            $inv = Invoice::find($id);
            if ($inv && $inv->status !== 'lunas') {
                $this->invoiceService->payInvoice((int)$id, [
                    'metode_pembayaran' => $metode,
                    'nominal_bayar'     => (float)$inv->sisa_piutang,
                    'tanggal_bayar'     => now(),
                    'keterangan'        => 'Pembayaran Massal (Bulk Payment)',
                ], $user?->id);
                $count++;
            }
        }

        ActivityLogService::log(
            'SUCCESS',
            'Pembayaran Invoice Massal',
            "Melunasi {$count} invoice secara massal dengan metode {$metode}",
            $user->username ?? 'Finance'
        );

        return redirect()->back()->with('sukses', "✅ Berhasil memproses pelunasan untuk {$count} invoice!");
    }

    /**
     * Delete an invoice (Superadmin / Admin / Finance).
     */
    public function invoiceDestroy(int $id): RedirectResponse
    {
        $user = Auth::user();
        if (!$user?->isSuperAdmin() && $user?->role !== 'admin' && $user?->role !== 'finance') {
            return redirect()->back()->with('error', 'Anda tidak memiliki izin untuk menghapus invoice.');
        }

        $invoice = Invoice::findOrFail($id);
        $nomor = $invoice->nomor_invoice;
        $nama = $invoice->pelanggan_nama;

        $invoice->delete();

        ActivityLogService::log(
            'WARNING',
            'Hapus Invoice',
            "Menghapus invoice {$nomor} ({$nama})",
            $user->username ?? 'Finance'
        );

        return redirect()->back()->with('sukses', "🗑️ Invoice {$nomor} ({$nama}) berhasil dihapus.");
    }

    /**
     * Get pre-formatted WhatsApp billing notification message template.
     */
    public function invoiceWaTemplate(int $id)
    {
        $invoice = Invoice::findOrFail($id);
        $setting = Setting::getSetting();
        $ispName = $setting->nama_isp ?: 'EONET';

        // Resolve clean customer phone number
        $target = $invoice->pelanggan_telepon ?: ($invoice->dataSheet->telepon ?? '');
        $cleanPhone = preg_replace('/[^0-9]/', '', (string)$target);
        if (str_starts_with($cleanPhone, '0')) {
            $cleanPhone = '62' . substr($cleanPhone, 1);
        } elseif (str_starts_with($cleanPhone, '8')) {
            $cleanPhone = '62' . $cleanPhone;
        }

        $tglInv = $invoice->tanggal_invoice ? $invoice->tanggal_invoice->format('d/m/Y') : date('d/m/Y');
        $tglJt = $invoice->tanggal_jatuh_tempo ? $invoice->tanggal_jatuh_tempo->format('d/m/Y') : ('20/' . sprintf('%02d', $invoice->periode_bulan) . '/' . $invoice->periode_tahun);

        $statusText = 'BELUM LUNAS ⏳';
        if ($invoice->status === 'lunas') {
            $statusText = 'LUNAS ✅';
        } elseif ($invoice->status === 'isolir') {
            $statusText = 'TERISOLIR ⛔';
        }

        $msg = "📢 *PEMBERITAHUAN TAGIHAN INTERNET - {$ispName}*\n";
        $msg .= "---------------------------------------------\n";
        $msg .= "Yth. Bpk/Ibu *{$invoice->pelanggan_nama}*\n";
        $msg .= "ID / PPPoE: *{$invoice->pelanggan_username}*\n\n";
        $msg .= "Berikut rincian tagihan internet Anda:\n";
        $msg .= "📄 *No. Invoice*: `{$invoice->nomor_invoice}`\n";
        $msg .= "📅 *Tgl. Invoice*: {$tglInv}\n";
        $msg .= "📆 *Periode*: {$invoice->periode_formatted}\n";
        $msg .= "📦 *Paket Layanan*: {$invoice->paket_nama}\n";
        $msg .= "💰 *Tarif Bulanan*: Rp " . number_format((float)$invoice->harga_paket, 0, ',', '.') . "\n";
        $msg .= "💵 *Total Tagihan*: {$invoice->formatted_total_tagihan}\n";
        if ($invoice->total_dibayar > 0) {
            $msg .= "✅ *Sudah Dibayar*: {$invoice->formatted_total_dibayar}\n";
            $msg .= "🔴 *Sisa Tagihan*: {$invoice->formatted_sisa_piutang}\n";
        }
        $msg .= "⏰ *Jatuh Tempo*: *{$tglJt}*\n";
        $msg .= "📌 *Status Tagihan*: *{$statusText}*\n";
        if ($invoice->keterangan) {
            $msg .= "📝 *Catatan*: {$invoice->keterangan}\n";
        }
        $msg .= "---------------------------------------------\n";
        $msg .= "💳 *Metode Pembayaran Transfer Bank:*\n";
        $msg .= "• *BCA*: 1234-567-890 (a.n {$ispName})\n";
        $msg .= "• *BRI*: 0987-654-321 (a.n {$ispName})\n";
        $msg .= "• *Mandiri*: 1122-3344-55 (a.n {$ispName})\n\n";
        $msg .= "Mohon lakukan pembayaran sebelum jatuh tempo agar koneksi internet tetap lancar dan terhindar dari isolir otomatis.\n\n";
        $msg .= "Jika sudah melakukan pembayaran, mohon konfirmasi dengan membalas pesan ini beserta foto bukti transfer.\n\n";
        $msg .= "Terima kasih atas kerja samanya. 🙏\n";
        $msg .= "*Layanan Pelanggan {$ispName}*";

        $waUrl = $cleanPhone ? ("https://wa.me/{$cleanPhone}?text=" . rawurlencode($msg)) : '';

        return response()->json([
            'success'       => true,
            'invoice_id'    => $invoice->id,
            'nomor_invoice' => $invoice->nomor_invoice,
            'nama'          => $invoice->pelanggan_nama,
            'telepon'       => $target,
            'clean_phone'   => $cleanPhone,
            'message'       => $msg,
            'wa_url'        => $waUrl,
        ]);
    }

    /**
     * Send WhatsApp billing notification / receipt link to customer.
     */
    public function invoiceSendWa(Request $request, int $id)
    {
        $invoice = Invoice::findOrFail($id);
        $setting = Setting::getSetting();
        $target = $request->input('phone') ?: ($invoice->pelanggan_telepon ?: ($invoice->dataSheet->telepon ?? ''));
        $cleanPhone = preg_replace('/[^0-9]/', '', (string)$target);
        if (str_starts_with($cleanPhone, '0')) {
            $cleanPhone = '62' . substr($cleanPhone, 1);
        } elseif (str_starts_with($cleanPhone, '8')) {
            $cleanPhone = '62' . $cleanPhone;
        }

        if (empty($cleanPhone)) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => "Nomor WhatsApp pelanggan {$invoice->pelanggan_nama} tidak tersedia."], 422);
            }
            return redirect()->back()->with('error', "Nomor WhatsApp pelanggan {$invoice->pelanggan_nama} tidak tersedia.");
        }

        $msg = $request->input('message');
        if (empty($msg)) {
            $ispName = $setting->nama_isp ?: 'EONET';
            $tglInv = $invoice->tanggal_invoice ? $invoice->tanggal_invoice->format('d/m/Y') : date('d/m/Y');
            $tglJt = $invoice->tanggal_jatuh_tempo ? $invoice->tanggal_jatuh_tempo->format('d/m/Y') : ('20/' . sprintf('%02d', $invoice->periode_bulan) . '/' . $invoice->periode_tahun);

            $statusText = 'BELUM LUNAS ⏳';
            if ($invoice->status === 'lunas') {
                $statusText = 'LUNAS ✅';
            } elseif ($invoice->status === 'isolir') {
                $statusText = 'TERISOLIR ⛔';
            }

            $msg = "📢 *PEMBERITAHUAN TAGIHAN INTERNET - {$ispName}*\n";
            $msg .= "---------------------------------------------\n";
            $msg .= "Yth. Bpk/Ibu *{$invoice->pelanggan_nama}*\n";
            $msg .= "ID / PPPoE: *{$invoice->pelanggan_username}*\n\n";
            $msg .= "Berikut rincian tagihan internet Anda:\n";
            $msg .= "📄 *No. Invoice*: `{$invoice->nomor_invoice}`\n";
            $msg .= "📅 *Tgl. Invoice*: {$tglInv}\n";
            $msg .= "📆 *Periode*: {$invoice->periode_formatted}\n";
            $msg .= "📦 *Paket Layanan*: {$invoice->paket_nama}\n";
            $msg .= "💰 *Tarif Bulanan*: Rp " . number_format((float)$invoice->harga_paket, 0, ',', '.') . "\n";
            $msg .= "💵 *Total Tagihan*: {$invoice->formatted_total_tagihan}\n";
            if ($invoice->total_dibayar > 0) {
                $msg .= "✅ *Sudah Dibayar*: {$invoice->formatted_total_dibayar}\n";
                $msg .= "🔴 *Sisa Tagihan*: {$invoice->formatted_sisa_piutang}\n";
            }
            $msg .= "⏰ *Jatuh Tempo*: *{$tglJt}*\n";
            $msg .= "📌 *Status Tagihan*: *{$statusText}*\n";
            if ($invoice->keterangan) {
                $msg .= "📝 *Catatan*: {$invoice->keterangan}\n";
            }
            $msg .= "---------------------------------------------\n";
            $msg .= "💳 *Metode Pembayaran Transfer Bank:*\n";
            $msg .= "• *BCA*: 1234-567-890 (a.n {$ispName})\n";
            $msg .= "• *BRI*: 0987-654-321 (a.n {$ispName})\n";
            $msg .= "• *Mandiri*: 1122-3344-55 (a.n {$ispName})\n\n";
            $msg .= "Mohon lakukan pembayaran tepat waktu agar koneksi internet tetap aktif dan lancar.\n\n";
            $msg .= "Jika sudah melakukan pembayaran, mohon konfirmasi dengan membalas pesan ini beserta foto/bukti transfer.\n\n";
            $msg .= "Terima kasih atas kepercayaan Anda. 🙏\n";
            $msg .= "*Layanan Pelanggan {$ispName}*";
        }

        try {
            \App\Services\NotificationService::sendWhatsApp($cleanPhone, $msg);

            \App\Services\ActivityLogService::log(
                'SUCCESS',
                'Blast Tagihan WhatsApp',
                "Kirim tagihan WA invoice {$invoice->nomor_invoice} ke {$cleanPhone} ({$invoice->pelanggan_nama})",
                Auth::user()->username ?? 'Finance'
            );

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => "Tagihan invoice {$invoice->nomor_invoice} berhasil dikirim ke WhatsApp {$cleanPhone}!",
                ]);
            }

            return redirect()->back()->with('sukses', "📲 Tagihan invoice {$invoice->nomor_invoice} berhasil dikirim ke WhatsApp {$cleanPhone}!");
        } catch (\Throwable $e) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Gagal mengirim pesan WhatsApp: ' . $e->getMessage()], 500);
            }
            return redirect()->back()->with('error', "Gagal mengirim pesan WhatsApp: " . $e->getMessage());
        }
    }

    /**
     * Bulk Send WhatsApp reminders for multiple selected invoices.
     */
    public function invoiceBulkSendWa(Request $request)
    {
        $ids = $request->input('invoice_ids', []);
        if (empty($ids) || !is_array($ids)) {
            return redirect()->back()->with('error', 'Pilih setidaknya satu invoice untuk dikirimkan WhatsApp.');
        }

        $sentCount = 0;
        $failedCount = 0;

        foreach ($ids as $id) {
            $invoice = Invoice::find($id);
            if (!$invoice) continue;

            $target = $invoice->pelanggan_telepon ?: ($invoice->dataSheet->telepon ?? '');
            $cleanPhone = preg_replace('/[^0-9]/', '', (string)$target);
            if (str_starts_with($cleanPhone, '0')) {
                $cleanPhone = '62' . substr($cleanPhone, 1);
            } elseif (str_starts_with($cleanPhone, '8')) {
                $cleanPhone = '62' . $cleanPhone;
            }

            if (empty($cleanPhone)) {
                $failedCount++;
                continue;
            }

            $setting = Setting::getSetting();
            $ispName = $setting->nama_isp ?: 'EONET';
            $tglInv = $invoice->tanggal_invoice ? $invoice->tanggal_invoice->format('d/m/Y') : date('d/m/Y');
            $tglJt = $invoice->tanggal_jatuh_tempo ? $invoice->tanggal_jatuh_tempo->format('d/m/Y') : ('20/' . sprintf('%02d', $invoice->periode_bulan) . '/' . $invoice->periode_tahun);

            $statusText = $invoice->status === 'lunas' ? 'LUNAS ✅' : ($invoice->status === 'isolir' ? 'TERISOLIR ⛔' : 'BELUM LUNAS ⏳');

            $msg = "📢 *PEMBERITAHUAN TAGIHAN INTERNET - {$ispName}*\n";
            $msg .= "Yth. Bpk/Ibu *{$invoice->pelanggan_nama}* ({$invoice->pelanggan_username})\n\n";
            $msg .= "• No. Invoice: *{$invoice->nomor_invoice}*\n";
            $msg .= "• Tgl. Invoice: *{$tglInv}*\n";
            $msg .= "• Paket: *{$invoice->paket_nama}*\n";
            $msg .= "• Total Tagihan: *{$invoice->formatted_total_tagihan}*\n";
            $msg .= "• Jatuh Tempo: *{$tglJt}*\n";
            $msg .= "• Status: *{$statusText}*\n\n";
            $msg .= "Mohon lakukan pembayaran tepat waktu. Konfirmasi transfer dapat dibalas pada chat ini.\nTerima kasih. 🙏";

            try {
                \App\Services\NotificationService::sendWhatsApp($cleanPhone, $msg);
                $sentCount++;
            } catch (\Throwable $e) {
                $failedCount++;
            }
        }

        \App\Services\ActivityLogService::log(
            'SUCCESS',
            'Blast WhatsApp Massal',
            "Kirim {$sentCount} pesan tagihan WhatsApp massal",
            Auth::user()->username ?? 'Finance'
        );

        return redirect()->back()->with('sukses', "📲 Berhasil mengirim pesan WhatsApp ke {$sentCount} pelanggan!" . ($failedCount > 0 ? " ({$failedCount} nomor tidak valid/gagal)" : ''));
    }

    /**
     * Export Invoices to CSV / Excel format.
     */
    public function invoiceExportCsv(Request $request)
    {
        $bulan = (int) $request->input('bulan', now()->month);
        $tahun = (int) $request->input('tahun', now()->year);
        $status = trim((string) $request->input('status', ''));

        $query = Invoice::query();
        if ($bulan > 0) $query->where('periode_bulan', $bulan);
        if ($tahun > 0) $query->where('periode_tahun', $tahun);
        if ($status && $status !== 'all') $query->where('status', $status);

        $invoices = $query->orderBy('id')->get();

        $filename = "Data_Invoice_EONET_{$tahun}_{$bulan}.csv";
        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($invoices) {
            $file = fopen('php://output', 'w');
            // UTF-8 BOM
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($file, [
                'No', 'Tanggal Invoice', 'No. Invoice', 'ID Customer', 'Username PPPoE',
                'Nama Pelanggan', 'No. Telepon', 'Alamat', 'Marketing', 'Teknisi',
                'Produk / Paket', 'Harga (Rp)', 'Tax (Rp)', 'Potongan (Rp)', 'Total Tagihan (Rp)',
                'Dibayar (Rp)', 'Sisa Piutang (Rp)', 'Status', 'Metode Bayar', 'Tanggal Bayar', 'Keterangan'
            ]);

            $no = 1;
            foreach ($invoices as $inv) {
                fputcsv($file, [
                    $no++,
                    $inv->tanggal_invoice ? $inv->tanggal_invoice->format('Y-m-d') : '',
                    $inv->nomor_invoice,
                    $inv->id_customer ?: '-',
                    $inv->pelanggan_username,
                    $inv->pelanggan_nama,
                    $inv->pelanggan_telepon ?: '-',
                    $inv->pelanggan_alamat ?: '-',
                    $inv->marketing_pic ?: '-',
                    $inv->teknisi_pic ?: '-',
                    $inv->paket_nama,
                    $inv->harga_paket,
                    $inv->tax,
                    $inv->potongan,
                    $inv->total_tagihan,
                    $inv->total_dibayar,
                    $inv->sisa_piutang,
                    $inv->status,
                    $inv->metode_pembayaran ?: '-',
                    $inv->tanggal_bayar ? $inv->tanggal_bayar->format('Y-m-d H:i:s') : '-',
                    $inv->keterangan ?: '-',
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Official Printable Invoice Document (Clean A4 Format without PPPoE/Marketing & with Master Paket PPN).
     */
    public function invoicePrint(int $id)
    {
        $invoice = Invoice::findOrFail($id);
        $setting = Setting::getSetting();

        // Smart match with Master Paket
        $clean = strtolower(trim((string)$invoice->paket_nama));
        $totalTagihan = (float) $invoice->total_tagihan;

        $paket = Paket::whereRaw('LOWER(nama_paket) = ?', [$clean])
            ->orWhereRaw('LOWER(mikrotik_profile) = ?', [$clean])
            ->first();

        if (!$paket && $totalTagihan > 0) {
            $paket = Paket::where('tarif_bulanan', $totalTagihan)->first();
        }

        if (!$paket) {
            preg_match('/\b(179|199|235|285|345|330|170|150|645|585|676)\b/i', $clean, $matches);
            if (!empty($matches[1])) {
                $num = $matches[1];
                $paket = Paket::where('nama_paket', 'like', "%{$num}%")
                    ->orWhere('mikrotik_profile', 'like', "%{$num}%")
                    ->orWhere('tarif_bulanan', 'like', "{$num}%")
                    ->first();
            }
        }

        // Calculate DPP and PPN from Master Paket
        if ($paket && (float)$paket->ppn > 0) {
            $ppnPercent = (float) $paket->ppn;
            if ($paket->harga_dasar > 0 && abs((float)$paket->tarif_bulanan - $totalTagihan) < 1000) {
                $hargaSebelumPajak = (float) $paket->harga_dasar;
                $tax = $totalTagihan - $hargaSebelumPajak;
            } else {
                $hargaSebelumPajak = round(($totalTagihan * 100) / (100 + $ppnPercent));
                $tax = $totalTagihan - $hargaSebelumPajak;
            }
        } else {
            // Default 11% standard broadband PPN
            $ppnPercent = 11;
            $hargaSebelumPajak = round(($totalTagihan * 100) / 111);
            $tax = $totalTagihan - $hargaSebelumPajak;
        }

        $kodeBarang = $paket ? ('PKT-' . sprintf('%03d', $paket->id)) : 'PKT-010';

        return view('finance.invoice.print', compact('invoice', 'setting', 'paket', 'kodeBarang', 'hargaSebelumPajak', 'tax', 'totalTagihan', 'ppnPercent'));
    }

    /**
     * Toggle (Isolir / Buka Isolir) Customer PPPoE via MikroTik directly from Invoice table.
     */
    public function invoiceToggleIsolir(Request $request, int $id)
    {
        $invoice = Invoice::findOrFail($id);
        $username = trim((string)$invoice->pelanggan_username);

        if (empty($username)) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Username PPPoE tidak ditemukan pada invoice ini.'], 422);
            }
            return redirect()->back()->with('error', 'Username PPPoE tidak ditemukan.');
        }

        $enable = $request->has('enable') ? $request->boolean('enable') : ($invoice->status === 'isolir');
        $alasan = trim((string)$request->input('keterangan', ''));

        $mikrotik = new \App\Services\MikrotikService();
        $res = $mikrotik->togglePppoeSecret($username, $enable);

        if (!$res['success']) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $res['message']], 400);
            }
            return redirect()->back()->with('error', $res['message']);
        }

        // Update Invoice status
        if (!$enable) {
            $invoice->status = 'isolir';
            if ($alasan) {
                $invoice->keterangan = $invoice->keterangan ? ($invoice->keterangan . " | " . $alasan) : $alasan;
            }
        } else {
            $invoice->status = ($invoice->total_dibayar >= $invoice->total_tagihan) ? 'lunas' : 'belum_lunas';
        }
        $invoice->save();

        // Sync DataSheet and Pelanggan models
        $newStatusLangganan = $enable ? 'aktif' : 'isolir';
        \App\Models\DataSheet::where('username_pppoe', $username)->update([
            'status_langganan' => $newStatusLangganan
        ]);
        \App\Models\Pelanggan::where('username', $username)->update([
            'status' => $enable ? 'Aktif' : 'Isolir'
        ]);

        $actionName = $enable ? 'Buka Isolir PPPoE' : 'Isolir / Blokir PPPoE';
        \App\Services\ActivityLogService::log(
            'SUCCESS',
            $actionName,
            "{$actionName} untuk pelanggan {$invoice->pelanggan_nama} ({$username}) dari tabel invoice. Catatan: " . ($alasan ?: '-'),
            Auth::user()->username ?? 'Finance'
        );

        $msg = $enable 
            ? "🟢 Berhasil membuka isolir untuk {$invoice->pelanggan_nama} ({$username})!" 
            : "⛔ Berhasil mengisolir / memblokir pelanggan {$invoice->pelanggan_nama} ({$username})!";

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $msg,
                'is_active' => $enable,
                'status' => $invoice->status,
                'status_badge' => $invoice->status_badge,
                'keterangan' => $invoice->keterangan,
            ]);
        }

        return redirect()->back()->with('sukses', $msg);
    }

    /**
     * Bulk Toggle Isolir for multiple invoices.
     */
    public function invoiceBulkToggleIsolir(Request $request)
    {
        $ids = $request->input('invoice_ids', []);
        $action = $request->input('action', 'isolir'); // isolir / buka_isolir
        $enable = ($action === 'buka_isolir');
        $alasan = trim((string)$request->input('keterangan', 'Isolir Massal Finance'));

        if (empty($ids) || !is_array($ids)) {
            return redirect()->back()->with('error', 'Pilih setidaknya satu invoice.');
        }

        $mikrotik = new \App\Services\MikrotikService();
        $successCount = 0;

        foreach ($ids as $id) {
            $inv = Invoice::find($id);
            if (!$inv || empty($inv->pelanggan_username)) continue;

            $res = $mikrotik->togglePppoeSecret($inv->pelanggan_username, $enable);
            if ($res['success']) {
                if (!$enable) {
                    $inv->status = 'isolir';
                    if ($alasan) {
                        $inv->keterangan = $inv->keterangan ? ($inv->keterangan . " | " . $alasan) : $alasan;
                    }
                } else {
                    $inv->status = ($inv->total_dibayar >= $inv->total_tagihan) ? 'lunas' : 'belum_lunas';
                }
                $inv->save();

                \App\Models\DataSheet::where('username_pppoe', $inv->pelanggan_username)->update([
                    'status_langganan' => $enable ? 'aktif' : 'isolir'
                ]);
                \App\Models\Pelanggan::where('username', $inv->pelanggan_username)->update([
                    'status' => $enable ? 'Aktif' : 'Isolir'
                ]);
                $successCount++;
            }
        }

        $actionText = $enable ? 'dibuka isolir-nya' : 'diisolir / diblokir';
        $msg = "⚡ Berhasil memproses {$successCount} pelanggan untuk {$actionText}!";

        return redirect()->back()->with('sukses', $msg);
    }

    /**
     * Quick Update Invoice Note / Catatan.
     */
    public function invoiceUpdateNote(Request $request, int $id)
    {
        $invoice = Invoice::findOrFail($id);
        $note = trim((string)$request->input('keterangan', ''));
        $invoice->keterangan = $note;
        $invoice->save();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Catatan invoice berhasil diperbarui!',
                'keterangan' => $invoice->keterangan,
            ]);
        }

        return redirect()->back()->with('sukses', 'Catatan invoice berhasil diperbarui!');
    }
}

