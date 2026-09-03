<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WarehouseItem;
use App\Models\WarehouseRequest;
use App\Models\WarehouseRequestItem;
use App\Models\WarehouseReturn;
use App\Models\WarehouseStockMutation;
use App\Models\InventoryAsset;
use App\Services\MikrotikService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WarehouseController extends Controller
{
    protected $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        $this->mikrotik = $mikrotik;
    }

    /**
     * Display the Warehouse Management Dashboard.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $setting = Setting::getSetting();
        $telemetry = $this->mikrotik->getTelemetry(false);

        $isGudangOrAdmin = $user->isSuperAdmin() || $user->role === 'gudang' || $user->hasPermission('warehouse_master');

        // Non-gudang divisions (TL, Teknisi, CS, NOC, Finance) default to 'requests' tab
        $activeTab = $request->query('tab', $isGudangOrAdmin ? 'master' : 'requests');
        if (!$isGudangOrAdmin && !in_array($activeTab, ['requests'])) {
            $activeTab = 'requests';
        }

        $search = $request->query('q');
        $kategoriFilter = $request->query('kategori');

        // 1. Items Query
        $kondisiFilter = $request->query('kondisi', 'all');
        $itemsQuery = WarehouseItem::query();
        if ($search) {
            $itemsQuery->where(function ($q) use ($search) {
                $q->where('nama_barang', 'like', "%{$search}%")
                  ->orWhere('kode_barang', 'like', "%{$search}%")
                  ->orWhere('lokasi_rak', 'like', "%{$search}%");
            });
        }
        if ($kategoriFilter && $kategoriFilter !== 'all') {
            $itemsQuery->where('kategori', $kategoriFilter);
        }
        if ($kondisiFilter && $kondisiFilter !== 'all') {
            $itemsQuery->where('kondisi', $kondisiFilter);
        }
        $items = $itemsQuery->orderBy('nama_barang', 'asc')->get();

        // 2. Requests Query
        $requestsQuery = WarehouseRequest::with([
            'user', 
            'financeApprover', 
            'gudangConfirmer', 
            'items.item', 
            'items.warehouseItem', 
            'ticket', 
            'actionUser', 
            'linkedActionTicket', 
            'replacedAsset',
            'warehouseReturn'
        ]);
        if (!$isGudangOrAdmin) {
            // For non-gudang staff, prioritize their own / team division requests
            if ($user->role !== 'admin' && $user->role !== 'noc' && $user->role !== 'tl') {
                $requestsQuery->where('user_id', $user->id);
            }
        }
        $requests = $requestsQuery->latest()->paginate(15, ['*'], 'requests_page');

        // 3. Returns Query (Retur Cabut Alat)
        $returns = WarehouseReturn::with(['ticket', 'teknisi', 'warehouseItem', 'gudangReceiver'])
            ->latest()
            ->paginate(15, ['*'], 'returns_page');

        // 4. Mutations Query
        $mutations = WarehouseStockMutation::with(['item', 'user'])
            ->latest()
            ->paginate(20, ['*'], 'mutations_page');

        // 5. KPI Stats (aggregated efficiently)
        $itemStats = WarehouseItem::selectRaw("
            COUNT(*) as total_items,
            COALESCE(SUM(stok), 0) as total_stock_units,
            COALESCE(SUM(CASE WHEN kondisi = 'baru' THEN stok ELSE 0 END), 0) as stock_baru,
            COALESCE(SUM(CASE WHEN kondisi = 'second' THEN stok ELSE 0 END), 0) as stock_second,
            COALESCE(SUM(CASE WHEN kondisi = 'rusak' THEN stok ELSE 0 END), 0) as stock_rusak,
            COALESCE(SUM(CASE WHEN stok <= min_stok THEN 1 ELSE 0 END), 0) as low_stock_count
        ")->first();

        $reqStats = WarehouseRequest::selectRaw("
            COALESCE(SUM(CASE WHEN status = 'pending_finance' THEN 1 ELSE 0 END), 0) as pending_finance,
            COALESCE(SUM(CASE WHEN status = 'pending_gudang' THEN 1 ELSE 0 END), 0) as pending_gudang,
            COALESCE(SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END), 0) as my_requests_count,
            COALESCE(SUM(CASE WHEN user_id = ? AND status IN ('pending_gudang', 'pending_finance') THEN 1 ELSE 0 END), 0) as my_pending_count,
            COALESCE(SUM(CASE WHEN user_id = ? AND status = 'completed' THEN 1 ELSE 0 END), 0) as my_approved_count
        ", [$user->id, $user->id, $user->id])->first();

        $pendingReturns = WarehouseReturn::where('status', 'pending_gudang')->count();

        $stats = [
            'total_items'       => (int) ($itemStats->total_items ?? 0),
            'total_stock_units' => (int) ($itemStats->total_stock_units ?? 0),
            'stock_baru'        => (int) ($itemStats->stock_baru ?? 0),
            'stock_second'      => (int) ($itemStats->stock_second ?? 0),
            'stock_rusak'       => (int) ($itemStats->stock_rusak ?? 0),
            'low_stock_count'   => (int) ($itemStats->low_stock_count ?? 0),
            'pending_finance'   => (int) ($reqStats->pending_finance ?? 0),
            'pending_gudang'    => (int) ($reqStats->pending_gudang ?? 0),
            'my_requests_count' => (int) ($reqStats->my_requests_count ?? 0),
            'my_pending_count'  => (int) ($reqStats->my_pending_count ?? 0),
            'my_approved_count' => (int) ($reqStats->my_approved_count ?? 0),
            'pending_returns'   => $pendingReturns,
        ];

        // Available tickets for linking (PSB / Dismantle)
        $psbTickets = Ticket::select(['id', 'ticket_number', 'pelanggan_nama', 'type', 'status'])
            ->where('type', 'psb')
            ->whereIn('status', ['pending', 'survey', 'ready_dispatch', 'assigned', 'in_progress'])
            ->latest('id')
            ->limit(30)
            ->get();

        $allItems = (!$search && $kategoriFilter === 'all' && $kondisiFilter === 'all') 
            ? $items 
            : WarehouseItem::orderBy('kondisi')->orderBy('nama_barang')->get();

        return view('warehouse.index', compact(
            'setting',
            'telemetry',
            'activeTab',
            'items',
            'allItems',
            'requests',
            'returns',
            'mutations',
            'stats',
            'psbTickets',
            'isGudangOrAdmin'
        ));
    }

    /**
     * Store new master item.
     */
    public function storeItem(Request $request)
    {
        $validated = $request->validate([
            'kode_barang'    => 'required|string|max:50|unique:warehouse_items,kode_barang',
            'nama_barang'    => 'required|string|max:255',
            'kategori'       => 'required|string',
            'kondisi'        => 'required|string|in:baru,second,rusak',
            'satuan'         => 'required|string|max:20',
            'stok'           => 'required|integer|min:0',
            'min_stok'       => 'required|integer|min:0',
            'harga_estimasi' => 'nullable|numeric|min:0',
            'lokasi_rak'     => 'nullable|string|max:100',
            'spesifikasi'    => 'nullable|string',
        ]);

        $item = WarehouseItem::create($validated);

        if ($item->stok > 0) {
            WarehouseStockMutation::create([
                'warehouse_item_id' => $item->id,
                'tipe'              => 'adjustment',
                'jumlah'            => $item->stok,
                'stok_sebelum'      => 0,
                'stok_sesudah'      => $item->stok,
                'referensi_type'    => 'initial_stock',
                'referensi_id'      => $item->id,
                'user_id'           => Auth::id(),
                'catatan'           => 'Stok Awal Pembuatan Master Barang (' . ucfirst($item->kondisi) . ')',
            ]);
        }

        return redirect()->route('warehouse.index', ['tab' => 'master'])
            ->with('sukses', "Barang '{$item->nama_barang}' [{$item->kondisi_label}] berhasil ditambahkan ke master inventaris!");
    }

    /**
     * Update master item.
     */
    public function updateItem(Request $request, $id)
    {
        $item = WarehouseItem::findOrFail($id);

        $validated = $request->validate([
            'kode_barang'    => "required|string|max:50|unique:warehouse_items,kode_barang,{$id}",
            'nama_barang'    => 'required|string|max:255',
            'kategori'       => 'required|string',
            'kondisi'        => 'required|string|in:baru,second,rusak',
            'satuan'         => 'required|string|max:20',
            'min_stok'       => 'required|integer|min:0',
            'harga_estimasi' => 'nullable|numeric|min:0',
            'lokasi_rak'     => 'nullable|string|max:100',
            'spesifikasi'    => 'nullable|string',
            'status'         => 'nullable|string',
        ]);

        $item->update($validated);

        return redirect()->route('warehouse.index', ['tab' => 'master'])
            ->with('sukses', "Data barang '{$item->nama_barang}' berhasil diperbarui!");
    }

    /**
     * Delete master item.
     */
    public function deleteItem($id)
    {
        if (!Auth::user()?->isSuperAdmin() && !Auth::user()?->hasPermission('warehouse_delete')) {
            return redirect()->back()->with('error', '⛔ Akses Ditolak: Anda tidak memiliki hak akses untuk menghapus data barang.');
        }

        $item = WarehouseItem::findOrFail($id);
        $name = $item->nama_barang;
        $item->delete();

        return redirect()->route('warehouse.index', ['tab' => 'master'])
            ->with('sukses', "Barang '{$name}' berhasil dihapus dari master inventaris!");
    }

    /**
     * Submit request / pengajuan barang (Restock Belanja, Divisi, atau PSB).
     */
    public function storeRequest(Request $request)
    {
        $request->validate([
            'tipe_request'       => 'required|string|in:restock_procurement,divisi_operational,psb_package',
            'kategori_kebutuhan' => 'nullable|string|in:tambah_baru,ganti_perangkat',
            'alasan'             => 'required|string',
            'alokasi_aset'       => 'nullable|string|max:255',
            'replaced_asset_id'  => 'nullable|exists:inventory_assets,id',
            'serial_number_lama' => 'nullable|string|max:100',
            'lampiran_foto'      => 'nullable|image|max:8192',
            'ticket_id'          => 'nullable|exists:tickets,id',
            'items'              => 'required|array|min:1',
            'items.*.item_id'    => 'required|exists:warehouse_items,id',
            'items.*.jumlah'     => 'required|integer|min:1',
        ]);

        $user = Auth::user();
        $nomorRequest = 'REQ-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));

        $status = ($request->tipe_request === 'restock_procurement') 
            ? 'pending_finance' 
            : 'pending_gudang';

        $alokasiAset = $request->input('alokasi_aset');
        if (empty($alokasiAset)) {
            $alokasiAset = ($request->tipe_request === 'psb_package') 
                ? 'Pelanggan Pasang Baru (PSB)' 
                : 'Aset Operasional ' . ucfirst($user->role ?? 'Divisi');
        }

        $lampiranFotoPath = null;
        if ($request->hasFile('lampiran_foto')) {
            $file = $request->file('lampiran_foto');
            $uploadDir = public_path('uploads/warehouse_requests');
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $filename = 'REQ_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move($uploadDir, $filename);
            $lampiranFotoPath = 'uploads/warehouse_requests/' . $filename;
        }

        DB::beginTransaction();
        try {
            $warehouseRequest = WarehouseRequest::create([
                'nomor_request'      => $nomorRequest,
                'tipe_request'       => $request->tipe_request,
                'kategori_kebutuhan' => $request->input('kategori_kebutuhan', 'tambah_baru'),
                'ticket_id'          => $request->ticket_id,
                'user_id'            => $user->id,
                'divisi'             => $user->role ?? 'teknisi',
                'alasan'             => $request->alasan,
                'alokasi_aset'       => $alokasiAset,
                'replaced_asset_id'  => $request->input('replaced_asset_id'),
                'serial_number_lama' => $request->input('serial_number_lama'),
                'lampiran_foto'      => $lampiranFotoPath,
                'status'             => $status,
            ]);

            foreach ($request->items as $itemData) {
                $whItem = WarehouseItem::find($itemData['item_id']);
                WarehouseRequestItem::create([
                    'warehouse_request_id' => $warehouseRequest->id,
                    'warehouse_item_id'    => $whItem->id,
                    'jumlah_diminta'       => (int) $itemData['jumlah'],
                    'jumlah_disetujui'     => (int) $itemData['jumlah'],
                    'satuan'               => $whItem->satuan ?? 'Unit',
                    'catatan'              => $itemData['catatan'] ?? null,
                ]);
            }

            DB::commit();

            return redirect()->route('warehouse.index', ['tab' => 'requests'])
                ->with('sukses', "Pengajuan barang {$nomorRequest} berhasil dikirim! Status: " . ($status === 'pending_finance' ? 'Menunggu Approval Finance' : 'Menunggu Approval Kepala Gudang'));
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Gagal membuat pengajuan barang: ' . $e->getMessage());
        }
    }

    /**
     * Finance Approves or Rejects procurement restock request.
     */
    public function approveFinance(Request $request, $id)
    {
        $req = WarehouseRequest::findOrFail($id);

        $action = $request->input('action', 'approve'); // approve / reject
        $catatan = $request->input('catatan_finance');

        if ($action === 'approve') {
            $req->update([
                'status'                 => 'approved_finance',
                'approved_by_finance_id' => Auth::id(),
                'approved_by_finance_at' => now(),
                'catatan_finance'        => $catatan,
            ]);

            return redirect()->route('warehouse.index', ['tab' => 'requests'])
                ->with('sukses', "Pengajuan restock {$req->nomor_request} disetujui Finance! Menunggu barang tiba di gudang.");
        } else {
            $req->update([
                'status'                 => 'rejected_finance',
                'approved_by_finance_id' => Auth::id(),
                'approved_by_finance_at' => now(),
                'catatan_finance'        => $catatan ?? 'Ditolak oleh Finance.',
            ]);

            return redirect()->route('warehouse.index', ['tab' => 'requests'])
                ->with('sukses', "Pengajuan restock {$req->nomor_request} telah ditolak.");
        }
    }

    /**
     * Kepala Gudang confirms restock arrival (PHYSICAL GOODS ARRIVED -> AUTO INCREASE STOCK).
     */
    public function confirmRestock(Request $request, $id)
    {
        $req = WarehouseRequest::with('items.item')->findOrFail($id);

        if ($req->status !== 'approved_finance' && $req->status !== 'pending_gudang') {
            return redirect()->back()->with('error', 'Status pengajuan belum disetujui Finance.');
        }

        DB::beginTransaction();
        try {
            foreach ($req->items as $rItem) {
                $whItem = $rItem->item;
                $stokSebelum = $whItem->stok;
                $jumlahMasuk = $rItem->jumlah_disetujui ?: $rItem->jumlah_diminta;
                $stokSesudah = $stokSebelum + $jumlahMasuk;

                $whItem->update(['stok' => $stokSesudah]);

                WarehouseStockMutation::create([
                    'warehouse_item_id' => $whItem->id,
                    'tipe'              => 'in_restock',
                    'jumlah'            => $jumlahMasuk,
                    'stok_sebelum'      => $stokSebelum,
                    'stok_sesudah'      => $stokSesudah,
                    'referensi_type'    => 'warehouse_request',
                    'referensi_id'      => $req->id,
                    'user_id'           => Auth::id(),
                    'catatan'           => "Restock Barang Sampai via {$req->nomor_request}",
                ]);
            }

            $req->update([
                'status'                 => 'completed',
                'confirmed_by_gudang_id' => Auth::id(),
                'confirmed_by_gudang_at' => now(),
                'catatan_gudang'         => $request->input('catatan_gudang', 'Barang fisik telah sampai di gudang dan dicek lengkap.'),
            ]);

            DB::commit();

            return redirect()->route('warehouse.index', ['tab' => 'requests'])
                ->with('sukses', "Konfirmasi penerimaan barang {$req->nomor_request} berhasil! Stok di Master Barang otomatis bertambah.");
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Gagal memproses konfirmasi barang: ' . $e->getMessage());
        }
    }

    /**
     * Kepala Gudang approves division or PSB material request (AUTO DEDUCT STOCK).
     */
    public function approveDivisiRequest(Request $request, $id)
    {
        $req = WarehouseRequest::with(['items.item', 'user'])->findOrFail($id);
        $action = $request->input('action', 'approve'); // approve / reject
        $catatan = $request->input('catatan_gudang');

        if ($action === 'reject') {
            $req->update([
                'status'                 => 'rejected',
                'confirmed_by_gudang_id' => Auth::id(),
                'confirmed_by_gudang_at' => now(),
                'catatan_gudang'         => $catatan ?? 'Permintaan material ditolak oleh Kepala Gudang.',
            ]);

            return redirect()->route('warehouse.index', ['tab' => 'requests'])
                ->with('sukses', "Permintaan material {$req->nomor_request} ditolak.");
        }

        // Check stock availability
        foreach ($req->items as $rItem) {
            $whItem = $rItem->item;
            $jumlahDiminta = (int) ($rItem->jumlah_disetujui ?: ($rItem->jumlah_diminta ?: 1));
            if ($whItem && $whItem->stok < $jumlahDiminta) {
                return redirect()->back()->with('error', "Stok barang '{$whItem->nama_barang}' tidak mencukupi! Tersisa {$whItem->stok} {$whItem->satuan}, diminta {$jumlahDiminta} {$whItem->satuan}.");
            }
        }

        DB::beginTransaction();
        try {
            foreach ($req->items as $rItem) {
                $whItem = $rItem->item;
                if ($whItem) {
                    $stokSebelum = (int) $whItem->stok;
                    $jumlahKeluar = (int) ($rItem->jumlah_disetujui ?: ($rItem->jumlah_diminta ?: 1));
                    $stokSesudah = max(0, $stokSebelum - $jumlahKeluar);

                    $whItem->update(['stok' => $stokSesudah]);

                    $mutationType = ($req->tipe_request === 'psb_package') ? 'out_psb' : 'out_divisi';

                    WarehouseStockMutation::create([
                        'warehouse_item_id' => $whItem->id,
                        'tipe'              => $mutationType,
                        'jumlah'            => $jumlahKeluar,
                        'stok_sebelum'      => $stokSebelum,
                        'stok_sesudah'      => $stokSesudah,
                        'referensi_type'    => 'warehouse_request',
                        'referensi_id'      => $req->id,
                        'user_id'           => Auth::id(),
                        'catatan'           => "Penyerahan Material ke " . ($req->user->nama ?? 'Divisi') . " ({$req->nomor_request})",
                    ]);

                    // AUTO CREATE / REGISTER TO INVENTORY ASSETS
                    InventoryAsset::create([
                        'warehouse_item_id'    => $whItem->id,
                        'kode_barang'          => $whItem->kode_barang,
                        'nama_barang'          => $whItem->nama_barang,
                        'kategori'             => $whItem->kategori,
                        'jumlah'               => $jumlahKeluar,
                        'satuan'               => $whItem->satuan ?? 'Unit',
                        'harga_satuan'         => $whItem->harga_estimasi ?? 0,
                        'total_nilai'          => $jumlahKeluar * ($whItem->harga_estimasi ?? 0),
                        'lokasi_aset'          => $req->alokasi_aset ?: 'Aset Kantor',
                        'pic_user_id'          => $req->user_id,
                        'warehouse_request_id' => $req->id,
                        'ticket_id'            => $req->ticket_id,
                        'nomor_referensi'      => $req->nomor_request,
                        'status'               => 'aktif',
                        'catatan'              => "Diserahkan dari Gudang via {$req->nomor_request} (" . ucfirst($req->kategori_kebutuhan ?? 'tambah_baru') . ")",
                    ]);
                }
            }

            $isSwap = ($req->kategori_kebutuhan === 'ganti_perangkat');
            $autoReturnId = null;

            if ($isSwap) {
                // Auto generate Retur Barang Rusak ke Gudang
                $nomorRetur = 'RET-' . date('ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(4));
                $oldItemName = 'Perangkat Lama Penggantian';
                if ($req->replaced_asset_id) {
                    $oldAsset = InventoryAsset::find($req->replaced_asset_id);
                    if ($oldAsset) {
                        $oldItemName = $oldAsset->nama_barang;
                        $oldAsset->update([
                            'status'  => 'pemeliharaan',
                            'catatan' => ($oldAsset->catatan ? $oldAsset->catatan . " | " : "") . "Sedang diganti dengan perangkat baru via {$req->nomor_request}",
                        ]);
                    }
                }

                $autoReturn = WarehouseReturn::create([
                    'nomor_retur'       => $nomorRetur,
                    'ticket_id'         => $req->ticket_id,
                    'teknisi_id'        => $req->user_id,
                    'pelanggan_nama'    => "Penggantian Perangkat " . ($req->alokasi_aset ?: 'Lokasi'),
                    'nama_barang'       => $oldItemName,
                    'serial_number'     => $req->serial_number_lama ?: 'SN-SWAP-' . date('Ymd'),
                    'kondisi'           => 'rusak_total',
                    'status'            => 'pending_gudang',
                    'catatan_teknisi'   => "Auto-Retur Pergantian Alat Lama ({$req->nomor_request})",
                ]);
                $autoReturnId = $autoReturn->id;

                $finalStatus = 'pending_return_gudang';
                $gudangNote = $catatan ?: 'Material pengganti diserahkan. Menunggu teknisi mengembalikan fisik alat rusak ke gudang.';
            } else {
                $finalStatus = 'completed';
                $gudangNote = $catatan ?: 'Material telah disetujui & diserahkan ke divisi/teknisi.';
            }

            $req->update([
                'status'                 => $finalStatus,
                'warehouse_return_id'    => $autoReturnId,
                'confirmed_by_gudang_id' => Auth::id(),
                'confirmed_by_gudang_at' => now(),
                'catatan_gudang'         => $gudangNote,
            ]);

            DB::commit();

            $msg = $isSwap 
                ? "Permintaan penggantian material {$req->nomor_request} disetujui! Data retur {$nomorRetur} otomatis diterbitkan. Status menunggu pengembalian fisik barang bekas ke gudang."
                : "Permintaan material {$req->nomor_request} berhasil disetujui & diserahkan! Stok otomatis terpotong dan aset bertambah.";

            return redirect()->route('warehouse.index', ['tab' => 'requests'])->with('sukses', $msg);
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Gagal memproses penyerahan material: ' . $e->getMessage());
        }
    }

    /**
     * Gudang Rejects Divisi Request.
     */
    public function rejectDivisiRequest(Request $request, $id)
    {
        $whRequest = WarehouseRequest::findOrFail($id);

        $whRequest->update([
            'status'                 => 'rejected',
            'confirmed_by_gudang_id' => Auth::id(),
            'confirmed_by_gudang_at' => now(),
            'catatan_gudang'         => $request->input('catatan_gudang', 'Ditolak oleh Kepala Gudang.'),
        ]);

        return redirect()->route('warehouse.index', ['tab' => 'requests'])
            ->with('info', "Pengajuan material '{$whRequest->nomor_request}' telah ditolak.");
    }

    /**
     * Action Tindak Lanjut NOC setelah material diserahkan gudang.
     * Pilihan: Pasang Sendiri (NOC) atau Teruskan ke Kepala Teknisi (Buat WO Lapangan).
     */
    public function actionNocFollowup(Request $request, $id)
    {
        $whReq = WarehouseRequest::findOrFail($id);
        $user = Auth::user();

        $actionType = $request->input('action_type'); // 'self_install' or 'dispatch_wo'

        if ($actionType === 'self_install') {
            $catatan = $request->input('catatan_action') ?: 'Pemasangan telah diselesaikan langsung oleh tim NOC di lokasi.';
            $whReq->update([
                'action_pengerjaan' => 'dikerjakan_sendiri',
                'action_done_at'    => now(),
                'action_by_user_id' => $user->id,
                'catatan_gudang'    => ($whReq->catatan_gudang ? $whReq->catatan_gudang . "\n" : '') . 
                                       "🛠️ [NOC Action] Dipasang langsung oleh {$user->nama} pada " . now()->format('d/m/Y H:i') . ". ({$catatan})",
            ]);

            return redirect()->route('warehouse.index', ['tab' => 'requests'])
                ->with('sukses', "Pemasangan perangkat pada pengajuan {$whReq->nomor_request} berhasil diselesaikan langsung oleh NOC tanpa alur WO.");
        }

        if ($actionType === 'dispatch_wo') {
            $lokasi = $whReq->target_lokasi ?: ($whReq->alokasi_aset ?: 'POP / Jaringan Lapangan');
            $itemsList = $whReq->items->map(function ($it) {
                $qty = $it->jumlah_disetujui ?: ($it->jumlah_diminta ?: 1);
                return ($it->warehouseItem->nama_barang ?? 'Barang') . " ({$qty} " . ($it->warehouseItem->satuan ?? ($it->satuan ?? 'Unit')) . ")";
            })->implode(', ');

            $alasan = $whReq->alasan ?: 'Penggantian / Pemasangan perangkat baru dari gudang';
            $catatan = $request->input('catatan_action', 'Mohon lakukan pemasangan / swap perangkat baru di lokasi sesuai alokasi aset.');

            $ticketNum = Ticket::generateTicketNumber('wo');
            $newTicket = Ticket::create([
                'ticket_number'     => $ticketNum,
                'type'              => 'trouble',
                'kategori'          => 'Pergantian Perangkat / WO Lapangan',
                'prioritas'         => $request->input('prioritas', 'high'),
                'pelanggan_nama'    => "WO Maintenance: {$lokasi}",
                'alamat'            => "Lokasi Pemasangan: {$lokasi}. Detail Material: {$itemsList}. Keterangan: {$alasan}",
                'status'            => 'ready_dispatch', // Langsung siap didisposisi oleh Team Leader
                'deskripsi_keluhan' => "Work Order Pemasangan Material dari Permintaan {$whReq->nomor_request}.\nLokasi: {$lokasi}\nMaterial: {$itemsList}\nCatatan NOC: {$catatan}",
                'created_by'        => $user->id,
                'validated_by'      => $user->id,
                'validated_at'      => now(),
            ]);

            $whReq->update([
                'action_pengerjaan'       => 'disposisi_wo',
                'action_done_at'          => now(),
                'action_by_user_id'       => $user->id,
                'linked_action_ticket_id' => $newTicket->id,
                'catatan_gudang'          => ($whReq->catatan_gudang ? $whReq->catatan_gudang . "\n" : '') . 
                                             "📋 [WO Lapangan] Diteruskan ke Kepala Teknisi dengan nomor WO: #{$ticketNum} oleh {$user->nama} pada " . now()->format('d/m/Y H:i') . ".",
            ]);

            return redirect()->route('warehouse.index', ['tab' => 'requests'])
                ->with('sukses', "Work Order Lapangan #{$ticketNum} berhasil diterbitkan dan masuk ke antrean Kepala Teknisi untuk pengerjaan!");
        }

        return redirect()->back()->with('error', 'Pilihan tindakan tidak valid.');
    }

    /**
     * Kepala Gudang verifies physical return of dismantled hardware (AUTO RESOLVE TICKET).
     */
    public function receiveReturn(Request $request, $id)
    {
        $ret = WarehouseReturn::with('ticket')->findOrFail($id);

        $kondisi = $request->input('kondisi', 'layak_pakai');
        $catatan = $request->input('catatan_gudang', 'Fisik alat cabut telah diterima dan diverifikasi oleh Kepala Gudang.');
        $masukkanKeStok = $request->boolean('masukkan_ke_stok', false);
        $targetItemId = $request->input('warehouse_item_id');

        DB::beginTransaction();
        try {
            $finalItemId = null;
            if ($masukkanKeStok && $targetItemId) {
                if ($targetItemId === 'auto_second') {
                    $baseName = $ret->nama_barang ?: 'Perangkat Cabut';
                    $whItem = WarehouseItem::where('nama_barang', 'like', "%{$baseName}%")
                        ->where('kondisi', 'second')
                        ->first();

                    if (!$whItem) {
                        $whItem = WarehouseItem::create([
                            'kode_barang'    => 'SEC-' . strtoupper(\Illuminate\Support\Str::random(6)),
                            'nama_barang'    => $baseName . ' (Second / Bekas)',
                            'kategori'       => 'onu_modem',
                            'kondisi'        => 'second',
                            'satuan'         => 'Unit',
                            'stok'           => 1,
                            'min_stok'       => 2,
                            'harga_estimasi' => 0,
                            'lokasi_rak'     => 'Rak B1 - Second Retur',
                            'spesifikasi'    => "Perangkat bekas hasil retur cabut alat (SN: {$ret->serial_number})",
                        ]);

                        WarehouseStockMutation::create([
                            'warehouse_item_id' => $whItem->id,
                            'tipe'              => 'in_return',
                            'jumlah'            => 1,
                            'stok_sebelum'      => 0,
                            'stok_sesudah'      => 1,
                            'referensi_type'    => 'warehouse_return',
                            'referensi_id'      => $ret->id,
                            'user_id'           => Auth::id(),
                            'catatan'           => "Retur Alat Cabut Pelanggan {$ret->pelanggan_nama} (SN: {$ret->serial_number})",
                        ]);
                    } else {
                        $stokSebelum = $whItem->stok;
                        $stokSesudah = $stokSebelum + 1;
                        $whItem->update(['stok' => $stokSesudah]);

                        WarehouseStockMutation::create([
                            'warehouse_item_id' => $whItem->id,
                            'tipe'              => 'in_return',
                            'jumlah'            => 1,
                            'stok_sebelum'      => $stokSebelum,
                            'stok_sesudah'      => $stokSesudah,
                            'referensi_type'    => 'warehouse_return',
                            'referensi_id'      => $ret->id,
                            'user_id'           => Auth::id(),
                            'catatan'           => "Retur Alat Cabut Pelanggan {$ret->pelanggan_nama} (SN: {$ret->serial_number})",
                        ]);
                    }
                    $finalItemId = $whItem->id;
                } elseif ($targetItemId === 'auto_rusak') {
                    $baseName = $ret->nama_barang ?: 'Perangkat Cabut';
                    $whItem = WarehouseItem::where('nama_barang', 'like', "%{$baseName}%")
                        ->where('kondisi', 'rusak')
                        ->first();

                    if (!$whItem) {
                        $whItem = WarehouseItem::create([
                            'kode_barang'    => 'RSK-' . strtoupper(\Illuminate\Support\Str::random(6)),
                            'nama_barang'    => $baseName . ' (Rusak / Afkir)',
                            'kategori'       => 'onu_modem',
                            'kondisi'        => 'rusak',
                            'satuan'         => 'Unit',
                            'stok'           => 1,
                            'min_stok'       => 0,
                            'harga_estimasi' => 0,
                            'lokasi_rak'     => 'Rak R1 - Barang Rusak',
                            'spesifikasi'    => "Perangkat rusak hasil retur (SN: {$ret->serial_number})",
                        ]);

                        WarehouseStockMutation::create([
                            'warehouse_item_id' => $whItem->id,
                            'tipe'              => 'in_return',
                            'jumlah'            => 1,
                            'stok_sebelum'      => 0,
                            'stok_sesudah'      => 1,
                            'referensi_type'    => 'warehouse_return',
                            'referensi_id'      => $ret->id,
                            'user_id'           => Auth::id(),
                            'catatan'           => "Retur Alat Rusak Pelanggan {$ret->pelanggan_nama} (SN: {$ret->serial_number})",
                        ]);
                    } else {
                        $stokSebelum = $whItem->stok;
                        $stokSesudah = $stokSebelum + 1;
                        $whItem->update(['stok' => $stokSesudah]);

                        WarehouseStockMutation::create([
                            'warehouse_item_id' => $whItem->id,
                            'tipe'              => 'in_return',
                            'jumlah'            => 1,
                            'stok_sebelum'      => $stokSebelum,
                            'stok_sesudah'      => $stokSesudah,
                            'referensi_type'    => 'warehouse_return',
                            'referensi_id'      => $ret->id,
                            'user_id'           => Auth::id(),
                            'catatan'           => "Retur Alat Rusak Pelanggan {$ret->pelanggan_nama} (SN: {$ret->serial_number})",
                        ]);
                    }
                    $finalItemId = $whItem->id;
                } else {
                    $whItem = WarehouseItem::find($targetItemId);
                    if ($whItem) {
                        $stokSebelum = $whItem->stok;
                        $stokSesudah = $stokSebelum + 1;
                        $whItem->update(['stok' => $stokSesudah]);

                        WarehouseStockMutation::create([
                            'warehouse_item_id' => $whItem->id,
                            'tipe'              => 'in_return',
                            'jumlah'            => 1,
                            'stok_sebelum'      => $stokSebelum,
                            'stok_sesudah'      => $stokSesudah,
                            'referensi_type'    => 'warehouse_return',
                            'referensi_id'      => $ret->id,
                            'user_id'           => Auth::id(),
                            'catatan'           => "Retur Alat Cabut Pelanggan {$ret->pelanggan_nama} (SN: {$ret->serial_number})",
                        ]);
                        $finalItemId = $whItem->id;
                    }
                }
            }

            $ret->update([
                'status'                => 'received_gudang',
                'kondisi'               => $kondisi,
                'received_by_gudang_id' => Auth::id(),
                'received_at'           => now(),
                'catatan_gudang'        => $catatan,
                'warehouse_item_id'     => $finalItemId ?: $ret->warehouse_item_id,
            ]);

            // AUTO CLOSE LINKED CABUT ALAT TICKET & DELETE PPPOE SECRET ON MIKROTIK
            if ($ret->ticket_id && $ret->ticket) {
                $ticket = $ret->ticket;
                $oldStatus = $ticket->status;
                $ticket->update([
                    'status'      => 'closed',
                    'closed_by'   => Auth::id(),
                    'closed_at'   => now(),
                    'resolved_at' => $ticket->resolved_at ?: now(),
                    'keterangan'  => ($ticket->keterangan ? $ticket->keterangan . "\n\n" : '') . 
                                     "✅ [Gudang] Alat fisik (SN: {$ret->serial_number}) telah diverifikasi & diterima oleh Kepala Gudang (" . (Auth::user()->nama ?? 'Gudang') . ") pada " . now()->format('d/m/Y H:i') . ". Tiket Cabut Alat RESMI DITUTUP (CLOSED), Secret PPPoE dihapus permanen dari MikroTik, dan status pelanggan diperbarui ke 'Cabut Alat'.",
                ]);

                $ticket->recordLog(
                    action: 'Fisik Alat Diterima Kepala Gudang & Tiket Cabut Alat Resmi Ditutup (CLOSED)',
                    fromStatus: $oldStatus,
                    toStatus: 'closed',
                    notes: "Perangkat fisik berhasil diverifikasi oleh Kepala Gudang. Secret PPPoE di MikroTik resmi dihapus permanen, dan status pelanggan dialihkan ke Cabut Alat/Uninstall."
                );

                // 1. Permanently delete PPPoE Secret from all active MikroTik routers
                $userToClean = trim((string)($ticket->pelanggan_username ?: ''));
                if (empty($userToClean) && !empty($ticket->id_customer)) {
                    $pRow = \App\Models\Pelanggan::where('id_customer', $ticket->id_customer)->first();
                    $userToClean = $pRow?->username ?: '';
                }
                
                if (!empty($userToClean)) {
                    $routers = \App\Models\Router::where('is_active', true)->get();
                    $routerList = $routers->isNotEmpty() ? $routers : [null];
                    foreach ($routerList as $rDev) {
                        try {
                            $mtService = new \App\Services\MikrotikService($rDev);
                            $mtService->removePppoeSecret($userToClean);
                            if (str_contains($userToClean, '@')) {
                                $baseName = explode('@', $userToClean)[0];
                                $mtService->removePppoeSecret($baseName);
                            }
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::warning("MikroTik remove secret on dismantle closed failed: " . $e->getMessage());
                        }
                    }
                }

                // 2. Update Pelanggan status to 'Cabut Alat' while preserving data & photos
                if (!empty($ticket->pelanggan_username) || !empty($ticket->id_customer) || !empty($ticket->pelanggan_nama)) {
                    $pelanggan = \App\Models\Pelanggan::where('username', $ticket->pelanggan_username)
                        ->orWhere('id_customer', $ticket->id_customer)
                        ->orWhere('nama', $ticket->pelanggan_nama)
                        ->first();
                    if ($pelanggan) {
                        $pelanggan->update([
                            'status' => 'Cabut Alat',
                        ]);
                    }
                }

                // 3. Auto sync to DataSheet & Google Sheet (Tab CABUT ALAT)
                \App\Models\DataSheet::syncFromTicket($ticket);
                \App\Services\GoogleSheetSyncService::syncTicketToGoogleSheetAsync($ticket);
            }

            // AUTO COMPLETE LINKED WAREHOUSE REQUEST (FOR SWAP REPLACEMENT)
            $linkedReq = WarehouseRequest::where('warehouse_return_id', $ret->id)->first();
            if ($linkedReq) {
                $linkedReq->update([
                    'status'         => 'completed',
                    'catatan_gudang' => ($linkedReq->catatan_gudang ? $linkedReq->catatan_gudang . "\n" : '') . 
                                        "✅ Fisik perangkat lama (" . ($ret->serial_number ?: 'SN') . ") telah diverifikasi & diterima oleh Kepala Gudang pada " . now()->format('d/m/Y H:i') . ".",
                ]);

                if ($linkedReq->replaced_asset_id) {
                    $oldAsset = InventoryAsset::find($linkedReq->replaced_asset_id);
                    if ($oldAsset) {
                        $oldAsset->update(['status' => 'rusak']);
                    }
                }
            }

            DB::commit();

            return redirect()->route('warehouse.index', ['tab' => 'returns'])
                ->with('sukses', "Retur alat {$ret->nomor_retur} berhasil dikonfirmasi diterima! Stok gudang telah diperbarui dan tiket terkait dinyatakan SELESAI TUNTAS.");
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Gagal memproses penerimaan retur: ' . $e->getMessage());
        }
    }

    /**
     * Kepala Gudang rejects return (e.g. SN mismatch or device not yet brought by technician).
     */
    public function rejectReturn(Request $request, $id)
    {
        $ret = WarehouseReturn::findOrFail($id);
        $catatan = $request->input('catatan_gudang', 'Fisik alat belum diterima atau serial number tidak sesuai.');

        $ret->update([
            'status'                => 'rejected_gudang',
            'received_by_gudang_id' => Auth::id(),
            'received_at'           => now(),
            'catatan_gudang'        => $catatan,
        ]);

        return redirect()->route('warehouse.index', ['tab' => 'returns'])
            ->with('sukses', "Status retur {$ret->nomor_retur} ditolak / dikembalikan ke teknisi.");
    }

    /**
     * Stock adjustment / Opname.
     */
    public function adjustStock(Request $request, $id)
    {
        $item = WarehouseItem::findOrFail($id);
        $request->validate([
            'stok_baru' => 'required|integer|min:0',
            'alasan'    => 'required|string',
        ]);

        $stokSebelum = $item->stok;
        $stokBaru = (int) $request->stok_baru;
        $selisih = $stokBaru - $stokSebelum;

        $item->update(['stok' => $stokBaru]);

        WarehouseStockMutation::create([
            'warehouse_item_id' => $item->id,
            'tipe'              => 'adjustment',
            'jumlah'            => abs($selisih),
            'stok_sebelum'      => $stokSebelum,
            'stok_sesudah'      => $stokBaru,
            'referensi_type'    => 'manual_adjustment',
            'referensi_id'      => $item->id,
            'user_id'           => Auth::id(),
            'catatan'           => "Penyesuaian Stok Manual: {$request->alasan} (" . ($selisih >= 0 ? "+{$selisih}" : "{$selisih}") . ")",
        ]);

        return redirect()->route('warehouse.index', ['tab' => 'master'])
            ->with('sukses', "Stok barang '{$item->nama_barang}' berhasil disesuaikan menjadi {$stokBaru} {$item->satuan}!");
    }

    /**
     * Delete a warehouse request.
     */
    public function deleteRequest($id)
    {
        if (!Auth::user()?->isSuperAdmin() && !Auth::user()?->hasPermission('warehouse_delete')) {
            return redirect()->back()->with('error', '⛔ Akses Ditolak: Anda tidak memiliki hak akses untuk menghapus pengajuan barang.');
        }

        $req = WarehouseRequest::findOrFail($id);
        $no = $req->nomor_request;
        $req->delete();

        return redirect()->route('warehouse.index', ['tab' => 'requests'])
            ->with('sukses', "Pengajuan barang {$no} berhasil dihapus!");
    }

    /**
     * Delete a warehouse return.
     */
    public function deleteReturn($id)
    {
        if (!Auth::user()?->isSuperAdmin() && !Auth::user()?->hasPermission('warehouse_delete')) {
            return redirect()->back()->with('error', '⛔ Akses Ditolak: Anda tidak memiliki hak akses untuk menghapus data retur barang.');
        }

        $ret = WarehouseReturn::findOrFail($id);
        $no = $ret->nomor_retur;
        $ret->delete();

        return redirect()->route('warehouse.index', ['tab' => 'returns'])
            ->with('sukses', "Data retur {$no} berhasil dihapus!");
    }

    /**
     * Delete / Rollback a stock mutation and revert item stock.
     */
    public function deleteMutation($id)
    {
        if (!Auth::user()?->isSuperAdmin() && !Auth::user()?->hasPermission('warehouse_delete')) {
            return redirect()->back()->with('error', '⛔ Akses Ditolak: Anda tidak memiliki hak akses untuk menghapus riwayat mutasi.');
        }

        $mutation = WarehouseStockMutation::with('item')->findOrFail($id);
        $item = $mutation->item;
        $tipe = $mutation->tipe;
        $jumlah = (int) $mutation->jumlah;
        $namaBarang = $item?->nama_barang ?? 'Barang';

        DB::beginTransaction();
        try {
            if ($item) {
                // If mutation was OUTGOING (stock was reduced), rollback means ADDING stock back
                if ($tipe === 'out_request') {
                    $item->increment('stok', $jumlah);
                } 
                // If mutation was INCOMING (stock was added), rollback means REDUCING stock back
                elseif (in_array($tipe, ['in_restock', 'in_return'])) {
                    $item->stok = max(0, $item->stok - $jumlah);
                    $item->save();
                } 
                // If mutation was ADJUSTMENT, rollback to stok_sebelum
                elseif ($tipe === 'adjustment') {
                    $item->stok = $mutation->stok_sebelum;
                    $item->save();
                }
            }

            $mutation->delete();
            DB::commit();

            return redirect()->route('warehouse.index', ['tab' => 'mutations'])
                ->with('sukses', "✅ Riwayat mutasi '{$namaBarang}' berhasil dihapus! Stok barang sebanyak {$jumlah} telah otomatis dikembalikan.");
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Gagal menghapus mutasi: ' . $e->getMessage());
        }
    }

    /**
     * Export Warehouse data to CSV/Excel with date range and status filters.
     */
    public function export(Request $request)
    {
        $rekapType = $request->query('rekap_type', 'requests'); // requests, items, returns, mutations
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $status = $request->query('status', 'all');
        $format = $request->query('format', 'excel'); // excel (.xls) or csv (.csv)

        $typeLabel = match($rekapType) {
            'items'     => 'Master_Stok_Barang',
            'returns'   => 'Retur_Barang_Cabut',
            'mutations' => 'Mutasi_Stok',
            default     => 'Pengajuan_Material',
        };

        $dateSuffix = ($startDate ?: 'Awal') . '_sd_' . ($endDate ?: 'Sekarang');
        $filenameBase = "Rekap_Gudang_{$typeLabel}_{$dateSuffix}";
        $sheetTitle = substr("Gudang_" . $typeLabel, 0, 31);

        $headers = [];
        $rows = [];

        if ($rekapType === 'requests') {
            $headers = [
                'No',
                'No. Request',
                'Tanggal Pengajuan',
                'Tipe Request',
                'Pemohon (User)',
                'Divisi / Role',
                'No. Tiket Terkait',
                'Pelanggan',
                'Keperluan / Pekerjaan',
                'Nama Barang',
                'Jumlah Diminta',
                'Jumlah Disetujui',
                'Satuan',
                'Status Request',
                'Catatan Gudang',
                'Catatan Finance',
                'Tanggal Update / Selesai',
            ];

            $query = WarehouseRequest::with(['user', 'ticket', 'items.warehouseItem'])->latest('id');
            if ($startDate) {
                $query->whereDate('created_at', '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate('created_at', '<=', $endDate);
            }
            if ($status && $status !== 'all') {
                $query->where('status', $status);
            }

            $requests = $query->get();
            $no = 1;
            foreach ($requests as $req) {
                $tglPengajuan = $req->created_at ? $req->created_at->format('d/m/Y H:i') : '-';
                $tglSelesai = $req->updated_at ? $req->updated_at->format('d/m/Y H:i') : '-';
                $pemohon = $req->user?->nama ?? ($req->user?->username ?? '-');
                $divisi = strtoupper($req->divisi ?: ($req->user?->role ?? '-'));
                $noTiket = $req->ticket ? ('#' . $req->ticket->ticket_number) : '-';
                $pelanggan = $req->ticket ? ($req->ticket->pelanggan_nama ?: '-') : '-';
                $tipeReq = match($req->tipe_request) {
                    'restock_procurement' => 'Belanja / Restock Gudang',
                    'divisi_operational'  => 'Permintaan Material Divisi',
                    'psb_package'         => 'Paket Pasang Baru (PSB)',
                    default               => ucfirst($req->tipe_request ?? 'Permintaan'),
                };

                if ($req->items->isNotEmpty()) {
                    foreach ($req->items as $item) {
                        $namaBarang = $item->warehouseItem?->nama_barang ?? ($item->catatan ?? '-');
                        $satuan = $item->satuan ?: ($item->warehouseItem?->satuan ?? 'Unit');
                        $rows[] = [
                            $no++,
                            $req->nomor_request,
                            $tglPengajuan,
                            $tipeReq,
                            $pemohon,
                            $divisi,
                            $noTiket,
                            $pelanggan,
                            $req->alasan ?: ($req->kategori_kebutuhan ?: '-'),
                            $namaBarang,
                            (int)$item->jumlah_diminta,
                            (int)($item->jumlah_disetujui ?? $item->jumlah_diminta),
                            $satuan,
                            strtoupper(str_replace('_', ' ', $req->status)),
                            $req->catatan_gudang ?: '-',
                            $req->catatan_finance ?: '-',
                            $tglSelesai,
                        ];
                    }
                } else {
                    $rows[] = [
                        $no++,
                        $req->nomor_request,
                        $tglPengajuan,
                        $tipeReq,
                        $pemohon,
                        $divisi,
                        $noTiket,
                        $pelanggan,
                        $req->alasan ?: ($req->kategori_kebutuhan ?: '-'),
                        '-',
                        0,
                        0,
                        '-',
                        strtoupper(str_replace('_', ' ', $req->status)),
                        $req->catatan_gudang ?: '-',
                        $req->catatan_finance ?: '-',
                        $tglSelesai,
                    ];
                }
            }
        } elseif ($rekapType === 'items') {
            $headers = [
                'No',
                'Kode Barang',
                'Nama Barang',
                'Kategori',
                'Kondisi',
                'Stok Saat Ini',
                'Stok Minimum',
                'Satuan',
                'Status Stok',
                'Harga Satuan (Rp)',
                'Total Nilai Stok (Rp)',
                'Lokasi Rak',
                'Keterangan',
            ];

            $query = WarehouseItem::orderBy('kondisi')->orderBy('nama_barang');
            if ($status && $status !== 'all') {
                if ($status === 'low') {
                    $query->whereColumn('stok', '<=', 'min_stok');
                } elseif ($status === 'normal') {
                    $query->whereColumn('stok', '>', 'min_stok');
                }
            }

            $items = $query->get();
            $no = 1;
            foreach ($items as $item) {
                $statusStok = ($item->stok <= $item->min_stok) ? 'MENIPIS / KRITIS' : 'AMAN';
                $totalNilai = ($item->harga_estimasi ?? ($item->harga_satuan ?? 0)) * $item->stok;
                $rows[] = [
                    $no++,
                    $item->kode_barang ?: '-',
                    $item->nama_barang,
                    ucfirst($item->kategori),
                    ucfirst($item->kondisi ?? 'Baru'),
                    (int)$item->stok,
                    (int)$item->min_stok,
                    $item->satuan,
                    $statusStok,
                    (int) ($item->harga_estimasi ?? ($item->harga_satuan ?? 0)),
                    (int) $totalNilai,
                    $item->lokasi_rak ?: '-',
                    $item->spesifikasi ?: ($item->keterangan ?: '-'),
                ];
            }
        } elseif ($rekapType === 'returns') {
            $headers = [
                'No',
                'Tanggal Retur',
                'No. Retur',
                'No. Tiket Dismantle',
                'Pelanggan',
                'Teknisi Pengembali',
                'Nama Barang / Alat',
                'Serial Number (SN)',
                'MAC Address',
                'Kondisi Fisik',
                'Status Verifikasi Gudang',
                'Diterima Oleh Gudang',
                'Tanggal Diterima',
                'Catatan Teknisi',
                'Catatan Gudang',
            ];

            $query = WarehouseReturn::with(['ticket', 'teknisi', 'gudangReceiver'])->latest('id');
            if ($startDate) {
                $query->whereDate('created_at', '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate('created_at', '<=', $endDate);
            }
            if ($status && $status !== 'all') {
                $query->where('status', $status);
            }

            $returns = $query->get();
            $no = 1;
            foreach ($returns as $ret) {
                $rows[] = [
                    $no++,
                    $ret->created_at ? $ret->created_at->format('d/m/Y H:i') : '-',
                    $ret->nomor_retur ?: '-',
                    $ret->ticket ? ('#' . $ret->ticket->ticket_number) : '-',
                    $ret->pelanggan_nama ?: ($ret->ticket ? $ret->ticket->pelanggan_nama : '-'),
                    $ret->teknisi ? $ret->teknisi->nama : '-',
                    $ret->nama_barang ?: 'Alat Cabut',
                    $ret->serial_number ?: '-',
                    $ret->mac_address ?: '-',
                    ucfirst(str_replace('_', ' ', $ret->kondisi ?? 'bekas')),
                    strtoupper(str_replace('_', ' ', $ret->status)),
                    $ret->gudangReceiver ? $ret->gudangReceiver->nama : '-',
                    $ret->received_at ? $ret->received_at->format('d/m/Y H:i') : '-',
                    $ret->catatan_teknisi ?: '-',
                    $ret->catatan_gudang ?: '-',
                ];
            }
        } elseif ($rekapType === 'mutations') {
            $headers = [
                'No',
                'Tanggal Mutasi',
                'Nama Barang',
                'Tipe Mutasi',
                'Jumlah',
                'Stok Sebelum',
                'Stok Sesudah',
                'User Eksekutor',
                'Catatan / Referensi',
            ];

            $query = WarehouseStockMutation::with(['item', 'user'])->latest('id');
            if ($startDate) {
                $query->whereDate('created_at', '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate('created_at', '<=', $endDate);
            }

            $mutations = $query->get();
            $no = 1;
            foreach ($mutations as $mut) {
                $rows[] = [
                    $no++,
                    $mut->created_at ? $mut->created_at->format('d/m/Y H:i') : '-',
                    $mut->item?->nama_barang ?? '-',
                    strtoupper(str_replace('_', ' ', $mut->tipe)),
                    (int)$mut->jumlah,
                    (int)$mut->stok_sebelum,
                    (int)$mut->stok_sesudah,
                    $mut->user?->nama ?? '-',
                    $mut->catatan ?: '-',
                ];
            }
        }

        return \App\Services\ExcelExportHelper::streamExport(
            $filenameBase,
            $sheetTitle,
            $headers,
            $rows,
            $format
        );
    }
}
