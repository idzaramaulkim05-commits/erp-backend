<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterWilayah;
use App\Models\Odp;
use App\Models\Olt;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketLog;
use App\Models\User;
use App\Models\WarehouseRequest;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ComprehensiveTicketController extends Controller
{
    /**
     * Real-time live ticket & task status poller (Zero delay, No F5 needed).
     */
    public function liveTicketCheck(Request $request): JsonResponse
    {
        $user = Auth::user();
        $userId = $user?->id;

        // 1. Fetch live queue counts
        $counts = [
            'total_active'   => Ticket::whereNotIn('status', ['closed', 'cancelled'])->count(),
            'ready_dispatch' => Ticket::whereIn('status', ['ready_dispatch', 'pending_survey'])->count(),
            'pending_noc'    => Ticket::where('status', 'pending_noc')->count(),
            'in_progress'    => Ticket::where('status', 'in_progress')->count(),
            'psb'            => Ticket::where('type', 'psb')->whereNotIn('status', ['closed', 'cancelled'])->count(),
            'dismantle'      => Ticket::where('type', 'dismantle')->whereNotIn('status', ['closed', 'cancelled'])->count(),
            'trouble'        => Ticket::where('type', 'trouble')->whereNotIn('status', ['closed', 'cancelled'])->count(),
            'my_tasks'       => $userId ? Ticket::where('assigned_to', $userId)->whereIn('status', ['assigned', 'in_progress', 'pending_sparepart'])->count() : 0,
        ];

        // 2. Latest active ticket info
        $latestTicket = Ticket::latest('id')->first();
        $latestActiveTicket = Ticket::whereNotIn('status', ['closed', 'cancelled'])->latest('id')->first();

        // 3. Global customer reply event from cache
        $latestReplyEvent = Cache::get('global_latest_reply_event');

        // 4. Warehouse live requests tracking
        $whRequestCount = WarehouseRequest::count();
        $whLatestReq = WarehouseRequest::with('requester')->latest('id')->first();
        $whPendingCount = WarehouseRequest::where('status', 'pending_gudang')->count();
        $whChecksum = md5("{$whRequestCount}-{$whPendingCount}-" . ($whLatestReq?->updated_at ?? ''));

        // 5. Global ticket state checksum to detect ANY status changes, assignments, or completions
        $latestUpdated = Ticket::max('updated_at');
        $checksum = md5(($latestUpdated ?? '') . '-' . implode('-', $counts) . '-' . ($latestTicket?->id ?? 0) . '-' . $whChecksum);

        return response()->json([
            'status'             => true,
            'counts'             => $counts,
            'checksum'           => $checksum,
            'latest_id'          => $latestTicket?->id ?? 0,
            'latest_active'      => $latestActiveTicket ? [
                'id'            => $latestActiveTicket->id,
                'ticket_number' => $latestActiveTicket->ticket_number,
                'type'          => $latestActiveTicket->type,
                'type_label'    => $latestActiveTicket->type_label,
                'customer_name' => $latestActiveTicket->pelanggan_nama,
                'status'        => $latestActiveTicket->status,
                'status_label'  => $latestActiveTicket->status_label,
                'assigned_to'   => $latestActiveTicket->assigned_to,
                'created_at'    => $latestActiveTicket->created_at?->format('H:i') . ' WIB',
            ] : null,
            'warehouse_checksum' => $whChecksum,
            'warehouse_latest_id'=> $whLatestReq?->id ?? 0,
            'warehouse_pending'  => $whPendingCount,
            'warehouse_latest'   => $whLatestReq ? [
                'id'         => $whLatestReq->id,
                'no_request' => $whLatestReq->nomor_request ?? ('REQ-' . $whLatestReq->id),
                'requester'  => $whLatestReq->requester?->name ?? 'Tim Lapangan',
                'keperluan'  => $whLatestReq->alasan ?? 'Permintaan Material',
                'status'     => $whLatestReq->status,
            ] : null,
            'reply_event'        => $latestReplyEvent,
            'timestamp'          => now()->timestamp,
        ]);
    }

    /**
     * Display listing of trouble tickets and PSB queue with filters and counters.
     */
    public function index(Request $request): View
    {
        $user = Auth::user();
        
        $isMarketingOnly = $user && ($user->role === 'marketing' || $user->role === 'sales');
        $isTechnicianOnly = !$isMarketingOnly && $user && ($user->role === 'teknisi' || ($user->hasPermission('tiket_teknisi') && !$user->hasPermission('tiket_tl') && !$user->hasPermission('tiket_noc') && $user->role !== 'admin'));

        // Intelligent role-based default tab routing
        if (!$request->has('tab')) {
            if ($isTechnicianOnly) {
                $tab = 'my_tasks';
            } elseif ($isMarketingOnly) {
                $tab = 'all';
            } elseif ($user && ($user->role === 'tl' || ($user->hasPermission('tiket_tl') && !$user->hasPermission('tiket_noc') && $user->role !== 'admin'))) {
                $tab = 'ready_dispatch';
            } elseif ($user && ($user->role === 'noc' || ($user->hasPermission('tiket_noc') && $user->role !== 'admin'))) {
                $tab = 'pending_noc';
            } else {
                $tab = 'all';
            }
        } else {
            $tab = $request->query('tab', 'all');
        }

        $search = trim($request->query('search', ''));
        $statusFilter = $request->query('status');
        $priorityFilter = $request->query('prioritas');
        $techFilter = $request->query('technician_id');
        $typeFilter = $request->query('type');

        // Base Query
        $query = Ticket::with(['odp', 'olt', 'creator', 'validator', 'technician'])->latest();

        if ($isMarketingOnly) {
            $query->where('type', 'psb')->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                  ->orWhere('nama_marketing', $user->nama)
                  ->orWhere('nama_marketing', $user->username);
            });
        }

        // If user is field technician, strictly filter to their assigned tickets only
        if ($isTechnicianOnly) {
            $query->where('assigned_to', $user->id);
            if (!in_array($tab, ['my_tasks', 'in_progress', 'resolved', 'trouble_done', 'psb_done', 'dismantle_done', 'pending_sparepart', 'trouble', 'psb', 'dismantle'])) {
                $tab = 'my_tasks';
            }
        }

        // Multi-Field Search
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'like', "%{$search}%")
                  ->orWhere('pelanggan_nama', 'like', "%{$search}%")
                  ->orWhere('pelanggan_username', 'like', "%{$search}%")
                  ->orWhere('pelanggan_telepon', 'like', "%{$search}%")
                  ->orWhere('pelanggan_telepon_alt', 'like', "%{$search}%")
                  ->orWhere('alamat', 'like', "%{$search}%")
                  ->orWhere('patokan_alamat', 'like', "%{$search}%");
            });
        }

        // Tab Presets
        switch ($tab) {
            case 'all':
                if ($isMarketingOnly) {
                    $query->whereNotIn('status', ['cancelled']);
                } else {
                    $query->whereNotIn('status', ['closed', 'cancelled']);
                }
                break;
            case 'trouble':
                $query->where('type', 'trouble')->whereNotIn('status', ['closed', 'cancelled']);
                break;
            case 'psb':
                if ($isMarketingOnly) {
                    $query->whereNotIn('status', ['cancelled']);
                } else {
                    $query->where('type', 'psb')->whereNotIn('status', ['closed', 'cancelled']);
                }
                break;
            case 'dismantle':
                $query->where('type', 'dismantle')->whereNotIn('status', ['closed', 'cancelled']);
                break;
            case 'pending_noc':
                $query->whereIn('status', ['pending_noc', 'pending_survey']);
                break;
            case 'ready_dispatch':
                $query->where('status', 'ready_dispatch');
                break;
            case 'pending_finance':
                $query->where('type', 'psb')->whereIn('payment_status', ['pending_cash_settlement', 'pending_transfer_verification']);
                break;
            case 'pending_warehouse':
                $query->where('status', 'pending_gudang');
                break;
            case 'my_tasks':
                if ($user) {
                    $query->where('assigned_to', $user->id)
                          ->whereIn('status', ['assigned', 'in_progress', 'pending_sparepart']);
                }
                break;
            case 'in_progress':
                if ($isMarketingOnly) {
                    $query->whereIn('status', ['ready_dispatch', 'assigned', 'in_progress', 'pending_sparepart']);
                } else {
                    $query->where('status', 'in_progress');
                }
                break;
            case 'pending_sparepart':
                $query->where('status', 'pending_sparepart');
                break;
            case 'trouble_done':
            case 'resolved_trouble':
                $query->where('type', 'trouble')->whereIn('status', ['resolved', 'closed']);
                break;
            case 'psb_done':
            case 'resolved_psb':
                $query->where('type', 'psb')->whereIn('status', ['resolved', 'closed']);
                break;
            case 'dismantle_done':
            case 'resolved_dismantle':
                $query->where('type', 'dismantle')->whereIn('status', ['resolved', 'closed']);
                break;
            case 'resolved':
                $query->whereIn('status', ['resolved', 'closed']);
                break;
            case 'all_history':
                // Tampilkan semua riwayat tiket termasuk yang sudah closed
                break;
        }

        // Explicit Query Filters
        if ($statusFilter && $statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }
        if ($priorityFilter && $priorityFilter !== 'all') {
            $query->where('prioritas', $priorityFilter);
        }
        if ($typeFilter && $typeFilter !== 'all') {
            $query->where('type', $typeFilter);
        }
        if ($techFilter && $techFilter !== 'all') {
            $query->where('assigned_to', $techFilter);
        }

        $ticketCacheKey = 'ticket_view_page_v3_' . md5(($user?->id ?? 'guest') . '|' . $tab . '|' . $search . '|' . $statusFilter . '|' . $priorityFilter . '|' . $typeFilter . '|' . $techFilter . '|' . $request->query('page', 1));
        $tickets = \Illuminate\Support\Facades\Cache::remember($ticketCacheKey, 6, function () use ($query) {
            return $query->paginate(15)->withQueryString();
        });

        // Operational Counter Statistics (Optimized Single Grouped Aggregation Query + Cache)
        $counts = \Illuminate\Support\Facades\Cache::remember('ticket_counts_v5_' . ($user?->id ?? 'guest') . '_' . ($isTechnicianOnly ? 'tech' : ($isMarketingOnly ? 'mkt' : 'all')), 15, function () use ($isTechnicianOnly, $isMarketingOnly, $user) {
            $baseQuery = Ticket::query();
            if ($isTechnicianOnly) {
                $baseQuery->where('assigned_to', $user->id);
            } elseif ($isMarketingOnly) {
                $baseQuery->where('type', 'psb')->where(function ($q) use ($user) {
                    $q->where('created_by', $user->id)
                      ->orWhere('nama_marketing', $user->nama)
                      ->orWhere('nama_marketing', $user->username);
                });
            }

            $grouped = (clone $baseQuery)->select('type', 'status', 'payment_status', DB::raw('count(*) as total'))
                ->groupBy('type', 'status', 'payment_status')
                ->get();

            $total = 0;
            $totalTrouble = 0; $troublePendingNoc = 0; $troubleActive = 0; $troubleDone = 0;
            $totalPsb = 0; $psbQueue = 0; $psbInProgress = 0; $psbDone = 0;
            $totalDismantle = 0; $dismantleActive = 0; $dismantleDone = 0;
            $pendingNoc = 0; $readyDispatch = 0; $pendingFinance = 0; $pendingWarehouse = 0; $inProgress = 0;
            $totalResolved = 0; $allHistory = 0;

            foreach ($grouped as $g) {
                $cnt = (int)$g->total;
                $allHistory += $cnt;
                $st = (string)$g->status;
                $tp = (string)$g->type;
                $ps = (string)$g->payment_status;

                if ($isMarketingOnly) {
                    if ($st !== 'cancelled') $total += $cnt;
                } else {
                    if (!in_array($st, ['closed', 'cancelled'])) {
                        $total += $cnt;
                    }
                }

                if ($tp === 'trouble') {
                    if (!in_array($st, ['closed', 'cancelled'])) $totalTrouble += $cnt;
                    if ($st === 'pending_noc') $troublePendingNoc += $cnt;
                    if (in_array($st, ['ready_dispatch', 'assigned', 'in_progress', 'pending_sparepart'])) $troubleActive += $cnt;
                    if (in_array($st, ['resolved', 'closed'])) $troubleDone += $cnt;
                } elseif ($tp === 'psb') {
                    if ($isMarketingOnly) {
                        if ($st !== 'cancelled') $totalPsb += $cnt;
                    } else {
                        if (!in_array($st, ['closed', 'cancelled'])) $totalPsb += $cnt;
                    }
                    if (in_array($st, ['ready_dispatch', 'pending_survey'])) $psbQueue += $cnt;
                    if (in_array($st, ['assigned', 'in_progress', 'pending_sparepart'])) $psbInProgress += $cnt;
                    if (in_array($st, ['resolved', 'closed'])) $psbDone += $cnt;
                    if (in_array($ps, ['pending_cash_settlement', 'pending_transfer_verification'])) $pendingFinance += $cnt;
                } elseif ($tp === 'dismantle') {
                    if (!in_array($st, ['closed', 'cancelled'])) $totalDismantle += $cnt;
                    if (in_array($st, ['ready_dispatch', 'assigned', 'in_progress', 'pending_sparepart'])) $dismantleActive += $cnt;
                    if (in_array($st, ['resolved', 'closed'])) $dismantleDone += $cnt;
                }

                if (in_array($st, ['pending_noc', 'resolved'])) $pendingNoc += $cnt;
                if ($st === 'ready_dispatch') $readyDispatch += $cnt;
                if ($st === 'pending_gudang') $pendingWarehouse += $cnt;
                if ($st === 'in_progress') $inProgress += $cnt;
                if (in_array($st, ['resolved', 'closed'])) $totalResolved += $cnt;
            }

            $resolvedToday = (clone $baseQuery)->whereIn('status', ['resolved', 'closed'])
                ->whereDate('updated_at', today())
                ->count();

            $myActive = $user ? Ticket::where('assigned_to', $user->id)
                ->whereIn('status', ['assigned', 'in_progress', 'pending_sparepart'])
                ->count() : 0;

            return [
                'total'              => $total,
                'total_trouble'      => $totalTrouble,
                'trouble_pending_noc'=> $troublePendingNoc,
                'trouble_active'     => $troubleActive,
                'trouble_done'       => $troubleDone,
                'total_psb'          => $totalPsb,
                'psb_queue'          => $psbQueue,
                'psb_in_progress'    => $psbInProgress,
                'psb_done'           => $psbDone,
                'total_dismantle'    => $totalDismantle,
                'dismantle_active'   => $dismantleActive,
                'dismantle_done'     => $dismantleDone,
                'pending_noc'        => $pendingNoc,
                'ready_dispatch'     => $readyDispatch,
                'pending_finance'    => $pendingFinance,
                'pending_warehouse'  => $pendingWarehouse,
                'in_progress'        => $inProgress,
                'resolved_today'     => $resolvedToday,
                'total_resolved'     => $totalResolved,
                'all_history'        => $allHistory,
                'my_active'          => $myActive,
            ];
        });

        // Reference Data (Cached 60s for 0ms retrieval)
        $technicians = \Illuminate\Support\Facades\Cache::remember('ticket_ref_technicians', 60, function () {
            $t = User::where('is_active', true)
                ->where(function ($q) {
                    $q->where('role', 'teknisi')
                      ->orWhere('role', 'tl')
                      ->orWhere('permissions', 'like', '%tiket_teknisi%');
                })
                ->orderBy('nama')
                ->get();
            return $t->isEmpty() ? User::where('is_active', true)->orderBy('nama')->get() : $t;
        });

        $odps = Odp::getCachedAll();
        $olts = Olt::getCachedAll();
        $setting = Setting::getSetting();
        $masterProvinces = \Illuminate\Support\Facades\Cache::remember('master_provinces_v2', 86400, fn() => \App\Models\MasterWilayah::select('provinsi_kode', 'provinsi_nama')->distinct()->get());
        $masterKabupatens = \Illuminate\Support\Facades\Cache::remember('master_kabupatens_v2', 86400, fn() => \App\Models\MasterWilayah::select('provinsi_kode', 'kabupaten_kode', 'kabupaten_nama')->distinct()->orderBy('kabupaten_nama')->get());
        $masterWilayah = collect([]);

        return view('ticket.index', compact('tickets', 'counts', 'technicians', 'odps', 'olts', 'setting', 'tab', 'search', 'masterWilayah', 'masterProvinces', 'masterKabupatens', 'isTechnicianOnly'));
    }

    /**
     * Dedicated Page for Registrasi Pelanggan Baru (PSB)
     */
    public function psbIndex(Request $request)
    {
        $user = Auth::user();
        $isTechnicianOnly = $user && $user->role === 'teknisi';
        $isMarketingOnly = $user && ($user->role === 'marketing' || $user->role === 'sales');

        $tab = $request->query('tab', 'active_queue');
        $search = trim($request->query('search', ''));
        $statusFilter = $request->query('status');

        $query = Ticket::with(['odp', 'olt', 'creator', 'validator', 'technician'])
            ->where('type', 'psb')
            ->latest();

        // Scope strictly for Marketing: Only show their own submitted registrations!
        if ($isMarketingOnly) {
            $query->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                  ->orWhere('nama_marketing', $user->nama)
                  ->orWhere('nama_marketing', $user->username);
            });
        }

        if ($tab === 'my_tasks' && $user) {
            $query->where('assigned_to', $user->id);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'like', "%{$search}%")
                  ->orWhere('pelanggan_nama', 'like', "%{$search}%")
                  ->orWhere('pelanggan_username', 'like', "%{$search}%")
                  ->orWhere('pelanggan_telepon', 'like', "%{$search}%")
                  ->orWhere('alamat', 'like', "%{$search}%")
                  ->orWhere('patokan_alamat', 'like', "%{$search}%");
            });
        }

        switch ($tab) {
            case 'active_queue':
                // Default: Hanya menampilkan antrean aktif yang sedang berjalan / butuh tindakan
                $query->whereNotIn('status', ['closed', 'cancelled']);
                break;
            case 'pending_survey':
                $query->where('status', 'pending_survey');
                break;
            case 'ready_dispatch':
                $query->where('status', 'ready_dispatch');
                break;
            case 'in_progress':
                $query->whereIn('status', ['assigned', 'in_progress', 'pending_sparepart']);
                break;
            case 'pending_vlan':
                $query->where('status', 'pending_noc');
                break;
            case 'need_qc':
                $query->where('status', 'resolved');
                break;
            case 'my_tasks':
                if ($user) {
                    $query->where('assigned_to', $user->id)
                          ->whereIn('status', ['assigned', 'in_progress', 'pending_sparepart']);
                }
                break;
            case 'resolved':
                $query->where('status', 'closed');
                break;
            case 'all_psb':
                // Semua data termasuk riwayat closed
                break;
        }

        if ($statusFilter && $statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        $tickets = $query->paginate(15)->withQueryString();

        if ($isTechnicianOnly) {
            $basePsb = Ticket::where('type', 'psb')->where('assigned_to', $user->id);
            $counts = [
                'total_psb'          => (clone $basePsb)->count(),
                'psb_active_queue'   => (clone $basePsb)->whereNotIn('status', ['closed', 'cancelled'])->count(),
                'psb_pending_survey' => 0,
                'psb_ready_dispatch' => 0,
                'psb_in_progress'    => (clone $basePsb)->whereIn('status', ['assigned', 'in_progress', 'pending_sparepart'])->count(),
                'psb_pending_vlan'   => (clone $basePsb)->where('status', 'pending_noc')->count(),
                'psb_need_qc'        => (clone $basePsb)->where('status', 'resolved')->count(),
                'psb_done'           => (clone $basePsb)->where('status', 'closed')->count(),
                'my_active'          => (clone $basePsb)->whereIn('status', ['assigned', 'in_progress', 'pending_sparepart'])->count(),
            ];
        } elseif ($isMarketingOnly) {
            $basePsb = Ticket::where('type', 'psb')->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                  ->orWhere('nama_marketing', $user->nama)
                  ->orWhere('nama_marketing', $user->username);
            });
            $counts = [
                'total_psb'          => (clone $basePsb)->count(),
                'psb_active_queue'   => (clone $basePsb)->whereNotIn('status', ['closed', 'cancelled'])->count(),
                'psb_pending_survey' => (clone $basePsb)->where('status', 'pending_survey')->count(),
                'psb_ready_dispatch' => (clone $basePsb)->where('status', 'ready_dispatch')->count(),
                'psb_in_progress'    => (clone $basePsb)->whereIn('status', ['assigned', 'in_progress', 'pending_sparepart'])->count(),
                'psb_pending_vlan'   => (clone $basePsb)->where('status', 'pending_noc')->count(),
                'psb_need_qc'        => (clone $basePsb)->where('status', 'resolved')->count(),
                'psb_done'           => (clone $basePsb)->where('status', 'closed')->count(),
                'my_active'          => (clone $basePsb)->whereNotIn('status', ['closed', 'cancelled'])->count(),
            ];
        } else {
            $counts = [
                'total_psb'          => Ticket::where('type', 'psb')->count(),
                'psb_active_queue'   => Ticket::where('type', 'psb')->whereNotIn('status', ['closed', 'cancelled'])->count(),
                'psb_pending_survey' => Ticket::where('type', 'psb')->where('status', 'pending_survey')->count(),
                'psb_ready_dispatch' => Ticket::where('type', 'psb')->where('status', 'ready_dispatch')->count(),
                'psb_in_progress'    => Ticket::where('type', 'psb')->whereIn('status', ['assigned', 'in_progress', 'pending_sparepart'])->count(),
                'psb_pending_vlan'   => Ticket::where('type', 'psb')->where('status', 'pending_noc')->count(),
                'psb_need_qc'        => Ticket::where('type', 'psb')->where('status', 'resolved')->count(),
                'psb_done'           => Ticket::where('type', 'psb')->where('status', 'closed')->count(),
                'my_active'          => $user ? Ticket::where('type', 'psb')->where('assigned_to', $user->id)->whereIn('status', ['assigned', 'in_progress', 'pending_sparepart'])->count() : 0,
            ];
        }

        $technicians = User::where('is_active', true)
            ->where(function ($q) {
                $q->where('role', 'teknisi')
                  ->orWhere('role', 'tl')
                  ->orWhere('permissions', 'like', '%tiket_teknisi%');
            })
            ->orderBy('nama')
            ->get();

        if ($technicians->isEmpty()) {
            $technicians = User::where('is_active', true)->orderBy('nama')->get();
        }

        $odps = Odp::orderBy('nama_odp')->get();
        $olts = Olt::orderBy('name')->get();
        $setting = Setting::getSetting();
        $masterProvinces = \Illuminate\Support\Facades\Cache::remember('master_provinces_v2', 86400, fn() => \App\Models\MasterWilayah::select('provinsi_kode', 'provinsi_nama')->distinct()->get());
        $masterKabupatens = \Illuminate\Support\Facades\Cache::remember('master_kabupatens_v2', 86400, fn() => \App\Models\MasterWilayah::select('provinsi_kode', 'kabupaten_kode', 'kabupaten_nama')->distinct()->orderBy('kabupaten_nama')->get());
        $masterWilayah = collect([]);
        $isPsbDedicated = true;

        return view('ticket.psb_index', compact('tickets', 'counts', 'technicians', 'odps', 'olts', 'setting', 'tab', 'search', 'masterWilayah', 'masterProvinces', 'masterKabupatens', 'isPsbDedicated', 'isTechnicianOnly', 'isMarketingOnly'));
    }

    /**
     * Action on PSB registration (Lanjutkan / Setujui, Pending Kendala, or Batalkan).
     */
    public function psbAction(Request $request, int $id): RedirectResponse
    {
        $ticket = Ticket::findOrFail($id);
        $action = $request->input('action'); // continue / pending / cancel
        $catatan = trim((string)$request->input('catatan', ''));
        $oldStatus = $ticket->status;

        if ($action === 'continue') {
            $ticket->status = 'ready_dispatch';
            $this->appendPsbActionNote($ticket, 'Lanjutkan', $catatan);
            $ticket->save();

            $ticket->recordLog(
                action: 'PSB Dilanjutkan (Siap Disposisi)',
                fromStatus: $oldStatus,
                toStatus: 'ready_dispatch',
                notes: $catatan ? "PSB dilanjutkan: {$catatan}" : "PSB disetujui & dilanjutkan ke status Siap Disposisi TL."
            );

            return redirect()->back()->with('sukses', "✅ Registrasi PSB {$ticket->ticket_number} ({$ticket->pelanggan_nama}) berhasil dilanjutkan! Siap didisposisikan ke teknisi.");
        } elseif ($action === 'pending') {
            $ticket->status = 'pending_survey';
            $this->appendPsbActionNote($ticket, 'Pending', $catatan);
            $ticket->save();

            $ticket->recordLog(
                action: 'PSB Di-Pending (Kendala / Menunggu Jadwal)',
                fromStatus: $oldStatus,
                toStatus: 'pending_survey',
                notes: $catatan ? "PSB di-pending: {$catatan}" : "PSB berstatus pending kendala."
            );

            return redirect()->back()->with('sukses', "⏸️ Registrasi PSB {$ticket->ticket_number} ({$ticket->pelanggan_nama}) berhasil di-pending.");
        } elseif ($action === 'cancel') {
            $ticket->status = 'cancelled';
            $this->appendPsbActionNote($ticket, 'Batal', $catatan);
            $ticket->save();

            $ticket->recordLog(
                action: 'PSB Dibatalkan',
                fromStatus: $oldStatus,
                toStatus: 'cancelled',
                notes: $catatan ? "PSB dibatalkan: {$catatan}" : "Registrasi PSB dibatalkan."
            );

            return redirect()->back()->with('sukses', "❌ Registrasi PSB {$ticket->ticket_number} ({$ticket->pelanggan_nama}) telah dibatalkan.");
        }

        return redirect()->back()->with('error', 'Aksi tidak valid.');
    }

    /**
     * Store newly created trouble ticket or PSB intake.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $user = Auth::user();

        $type = $request->input('type', 'trouble');
        $isBackbone = ($type === 'backbone' || $request->input('ticket_scope') === 'backbone');
        if ($isBackbone) {
            $type = 'backbone';
        }

        $rules = [
            'type'              => 'required|in:trouble,backbone,psb,dismantle,relokasi,maintenance',
            'kategori'          => 'required|string|max:100',
            'prioritas'         => 'required|in:low,normal,high,urgent',
            'pelanggan_nama'    => 'nullable|string|max:150',
            'nama_depan'        => 'nullable|string|max:100',
            'nama_belakang'     => 'nullable|string|max:100',
            'pelanggan_username'=> 'nullable|string|max:150',
            'pelanggan_telepon' => 'nullable|string|max:50',
            'nama_marketing'    => 'nullable|string|max:150',
            'alamat'            => 'nullable|string|max:500',
            'judul_insiden'     => 'nullable|string|max:200',
            'area_terdampak'    => 'nullable|string|max:500',
            'alasan_cabut'      => 'nullable|string|max:255',
            'foto_rumah'        => 'nullable',
            'foto_sebelum'      => 'nullable',
        ];

        if (!$isBackbone && $type !== 'psb') {
            $rules['alamat'] = 'required|string|max:500';
        }

        $request->validate($rules);

        $ticketNum = Ticket::generateTicketNumber($type);

        // Upload House Photo or use inherited photo from DataSheet
        $fotoRumahPath = null;
        if ($request->hasFile('foto_rumah')) {
            $fotoRumahPath = $request->file('foto_rumah')->store('tickets/houses', 'public');
        } elseif ($request->filled('datasheet_foto_rumah')) {
            $fotoRumahPath = trim((string) $request->input('datasheet_foto_rumah'));
        }

        if ($isBackbone) {
            $judulInsiden = trim((string)$request->input('judul_insiden'));
            if (empty($judulInsiden)) {
                $judulInsiden = 'Insiden Backbone / Jaringan Massal';
            }
            $pelangganNama = '[BACKBONE] ' . $judulInsiden;
            $namaDepan = null;
            $namaBelakang = null;
            $alamat = trim((string)$request->input('area_terdampak')) ?: (trim((string)$request->input('alamat')) ?: 'Area Jaringan Backbone');
            $usernamePppoe = null;
            $idCustomer = null;
            $pelangganTelepon = null;
        } else {
            // Auto calculate customer name from First & Last Name if provided
            $namaDepan = trim((string)$request->input('nama_depan'));
            $namaBelakang = trim((string)$request->input('nama_belakang'));
            $pelangganNama = trim((string)$request->input('pelanggan_nama'));

            if (!empty($namaDepan)) {
                $pelangganNama = trim($namaDepan . ' ' . $namaBelakang);
            }

            $usernamePppoe = trim((string)$request->input('pelanggan_username'));
            if (empty($pelangganNama)) {
                if (!empty($usernamePppoe)) {
                    $ds = \App\Models\DataSheet::where('username_pppoe', $usernamePppoe)->first();
                    if ($ds && !empty($ds->nama_pelanggan)) {
                        $pelangganNama = $ds->nama_pelanggan;
                    } elseif (preg_match('/^(?:\d+[-_])?([^@]+)(?:@(.+))?$/', $usernamePppoe, $mUser)) {
                        $pelangganNama = ucwords(str_replace(['.', '_', '-'], ' ', $mUser[1]));
                    } else {
                        $pelangganNama = $usernamePppoe;
                    }
                } else {
                    $pelangganNama = 'Pelanggan ' . date('d/m');
                }
            }

            $alamat = trim((string)$request->input('alamat'));
            $pelangganTelepon = $request->filled('pelanggan_telepon') ? trim($request->input('pelanggan_telepon')) : null;
        }

        // Wilayah Codes for PSB
        $provKode = $request->input('provinsi_kode', '18');
        $kabKode = $request->input('kabupaten_kode', '01');
        $kecKode = $request->input('kecamatan_kode', '05');
        $desaKode = $request->input('desa_kode', '02');

        // For Pasang Baru (PSB), ID Customer & PPPoE are strictly NOT allocated yet at registration time.
        // They will be officially allocated and activated in MikroTik when NOC assigns VLAN (Tahap 4).
        if ($type === 'psb') {
            $idCustomer = null;
            $usernamePppoe = null;
        } elseif (!$isBackbone) {
            $idCustomer = $request->input('id_customer');
        }

        // Upload Before / Rumah Photo (Dual Option: Upload File Manual / Inherit DataSheet)
        $fotoSebelumPath = null;
        $fotoRumahPath = null;
        if ($request->hasFile('foto_rumah')) {
            $fotoRumahPath = $request->file('foto_rumah')->store('tickets/evidence', 'public');
            $fotoSebelumPath = $fotoRumahPath;
        } elseif ($request->hasFile('foto_sebelum')) {
            $fotoSebelumPath = $request->file('foto_sebelum')->store('tickets/evidence', 'public');
            $fotoRumahPath = $fotoSebelumPath;
        } elseif ($request->filled('datasheet_foto_rumah')) {
            $fotoRumahPath = trim((string) $request->input('datasheet_foto_rumah'));
            $fotoSebelumPath = $fotoRumahPath;
        }

        // Smart Coordinates extraction from Google Maps shareloc URL if provided
        $lat = $request->input('latitude');
        $lng = $request->input('longitude');
        $shareloc = $request->input('shareloc_url');

        if ((empty($lat) || empty($lng)) && !empty($shareloc)) {
            $parsed = $this->parseCoordinatesFromUrl($shareloc);
            if ($parsed) {
                $lat = $parsed['lat'];
                $lng = $parsed['lng'];
            }
        }

        // Check for active unclosed dismantle ticket to prevent duplicate tickets
        if ($type === 'dismantle' && !empty($usernamePppoe)) {
            $cleanUser = explode('@', $usernamePppoe)[0];
            $existingActive = Ticket::where('type', 'dismantle')
                ->where(function($q) use ($usernamePppoe, $cleanUser) {
                    $q->where('pelanggan_username', $usernamePppoe)
                      ->orWhere('pelanggan_username', $cleanUser);
                })
                ->whereNotIn('status', ['closed', 'cancelled'])
                ->latest()
                ->first();

            if ($existingActive && !$request->boolean('force_duplicate')) {
                $errMsg = "⚠️ Pelanggan {$pelangganNama} ({$usernamePppoe}) sudah memiliki tiket Cabut Alat aktif (#{$existingActive->ticket_number} - {$existingActive->status_label}) yang belum diselesaikan secara tuntas!";
                if ($request->ajax() || $request->wantsJson()) {
                    return response()->json([
                        'success'         => false,
                        'has_duplicate'   => true,
                        'message'         => $errMsg,
                        'existing_ticket' => [
                            'ticket_number' => $existingActive->ticket_number,
                            'status_label'  => $existingActive->status_label,
                            'created_at'    => $existingActive->created_at?->translatedFormat('d M Y, H:i') ?? '',
                            'technician'    => $existingActive->technician?->nama ?? 'Belum Ditugaskan',
                            'url'           => route('ticket.show', $existingActive->id),
                        ]
                    ], 422);
                }
                return back()->with('error', $errMsg);
            }
        }

        // Determine Initial Status
        $initialStatus = in_array($type, ['psb', 'dismantle']) ? 'ready_dispatch' : 'pending_noc';
        $deskripsi = $request->input('deskripsi_keluhan') ?: ($request->input('deskripsi_insiden') ?: $request->input('deskripsi_masalah'));
        $ticketPayload = [
            'ticket_number'        => $ticketNum,
            'type'                 => $type,
            'kategori'             => $request->input('kategori'),
            'prioritas'            => $request->input('prioritas', 'normal'),
            'status'               => $initialStatus,
            'kategori_pelanggan'   => $request->input('kategori_pelanggan', 'MR'),
            'pelanggan_nama'       => $pelangganNama,
            'nama_depan'           => $namaDepan ?: null,
            'nama_belakang'        => $namaBelakang ?: null,
            'provinsi_kode'        => $provKode,
            'kabupaten_kode'       => $kabKode,
            'kecamatan_kode'       => $kecKode,
            'desa_kode'            => $desaKode,
            'id_customer'          => $idCustomer ?: null,
            'pelanggan_username'   => $usernamePppoe ?: null,
            'pppoe_password'       => $request->input('pppoe_password', '1'),
            'pelanggan_telepon'    => $pelangganTelepon,
            'pelanggan_telepon_alt'=> $request->filled('pelanggan_telepon_alt') ? trim($request->input('pelanggan_telepon_alt')) : null,
            'nama_marketing'       => $request->filled('nama_marketing') ? trim($request->input('nama_marketing')) : ($user && ($user->role === 'marketing' || $user->role === 'sales') ? ($user->nama ?: $user->username) : null),
            'alamat'               => $alamat,
            'patokan_alamat'       => $request->input('patokan_alamat'),
            'latitude'             => $lat,
            'longitude'            => $lng,
            'shareloc_url'         => $shareloc,
            'foto_rumah'           => $fotoRumahPath,
            'foto_sebelum'         => $fotoSebelumPath,
            'odp_id'               => $request->input('odp_id'),
            'olt_id'               => $request->input('olt_id'),
            'paket'                => $request->input('paket') ?: $request->input('paket_layanan'),
            'alasan_cabut'         => $request->input('alasan_cabut'),
            'deskripsi_keluhan'    => $deskripsi,
            'created_by'           => Auth::id(),
        ];

        $catatanCs = trim((string) $request->input('catatan_cs', ''));
        if (Schema::hasColumn('tickets', 'catatan_cs')) {
            $ticketPayload['catatan_cs'] = $catatanCs ?: null;
        } elseif ($catatanCs !== '') {
            $existingDescription = trim((string) ($ticketPayload['deskripsi_keluhan'] ?? ''));
            $ticketPayload['deskripsi_keluhan'] = trim($existingDescription . ($existingDescription !== '' ? "\n" : '') . '[CS] ' . $catatanCs);
        }

        $ticket = Ticket::create($ticketPayload);

        // Record Initial Log
        $logAction = match ($type) {
            'psb'       => 'Registrasi Pasang Baru (PSB) Masuk ke Antrean TL',
            'dismantle' => 'Perintah Cabut Alat (Dismantle) Masuk ke Antrean TL',
            'backbone'  => 'Insiden Backbone / Jaringan Massal Masuk ke Antrean NOC',
            default     => 'Pembuatan Tiket Baru (Menunggu Validasi NOC)',
        };
        $ticket->recordLog(
            action: $logAction,
            fromStatus: null,
            toStatus: $initialStatus,
            notes: "Tiket {$type} dibuat oleh " . (Auth::user()?->nama ?? 'Customer Service/Finance')
        );

        // Activity Log
        ActivityLogService::log(
            'INFO',
            'Buat Tiket',
            "Membuat tiket {$type} #{$ticketNum} untuk {$ticket->pelanggan_nama}",
            Auth::user()?->username ?? 'System'
        );

        // Auto Notify Customer WhatsApp if phone number exists
        if (!$isBackbone && !empty($ticket->pelanggan_telepon)) {
            NotificationService::notifyCustomerTicketCreated($ticket);
        }

        // Auto Sync to DataSheet only for retail customer tickets
        if (!$isBackbone) {
            \App\Models\DataSheet::syncFromTicket($ticket);
            \App\Services\GoogleSheetSyncService::syncTicketToGoogleSheetAsync($ticket);
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success'       => true,
                'message'       => "✅ Perintah {$type} #{$ticketNum} berhasil dibuat dan diteruskan ke antrean Team Leader!",
                'ticket_id'     => $ticket->id,
                'ticket_number' => $ticketNum,
                'pelanggan_nama'=> $ticket->pelanggan_nama,
                'id_customer'   => $ticket->id_customer,
                'pelanggan_username' => $ticket->pelanggan_username,
            ]);
        }

        if ($type === 'psb') {
            return redirect()->route('psb.index')
                             ->with('sukses', "✅ Pendaftaran Pasang Baru (PSB) #{$ticketNum} ({$ticket->pelanggan_nama}) berhasil didaftarkan dan diteruskan ke antrean Team Leader!");
        }

        return redirect()->route('ticket.index')
                         ->with('sukses', "✅ Tiket #{$ticketNum} ({$ticket->pelanggan_nama}) berhasil dibuat dan diteruskan ke antrean!");
    }

    /**
     * Display ticket detailed view with full photo inspection, Google Maps link, and timeline.
     */
    public function show(int $id): View
    {
        $ticket = Ticket::with(['odp', 'olt', 'creator', 'validator', 'teamLeader', 'technician', 'closer', 'logs.user'])
                        ->findOrFail($id);
        
        $technicians = User::where('is_active', true)
            ->where(function ($q) {
                $q->where('role', 'teknisi')
                  ->orWhere('role', 'tl')
                  ->orWhere('permissions', 'like', '%tiket_teknisi%');
            })
            ->orderBy('nama')
            ->get();

        if ($technicians->isEmpty()) {
            $technicians = User::where('is_active', true)->orderBy('nama')->get();
        }

        $odps = Odp::orderBy('nama_odp')->get();
        $olts = Olt::orderBy('name')->get();
        $setting = Setting::getSetting();

        // Query past tickets history for this same customer (by PPPoE username or phone)
        $pastTickets = Ticket::with(['technician', 'creator', 'validator'])
            ->where('id', '!=', $ticket->id)
            ->where(function ($q) use ($ticket) {
                if (!empty($ticket->pelanggan_username)) {
                    $q->where('pelanggan_username', $ticket->pelanggan_username);
                }
                if (!empty($ticket->pelanggan_telepon)) {
                    $q->orWhere('pelanggan_telepon', $ticket->pelanggan_telepon);
                }
            })
            ->latest()
            ->limit(10)
            ->get();
        $warehouseItems = \App\Models\WarehouseItem::where('status', 'aktif')->orderBy('nama_barang')->get();
        $warehouseRequests = \App\Models\WarehouseRequest::with(['items.item', 'gudangConfirmer'])
            ->where('ticket_id', $ticket->id)
            ->latest()
            ->get();
        $masterProvinces = \Illuminate\Support\Facades\Cache::remember('master_provinces_v2', 86400, fn() => \App\Models\MasterWilayah::select('provinsi_kode', 'provinsi_nama')->distinct()->get());
        $masterKabupatens = \Illuminate\Support\Facades\Cache::remember('master_kabupatens_v2', 86400, fn() => \App\Models\MasterWilayah::select('provinsi_kode', 'kabupaten_kode', 'kabupaten_nama')->distinct()->orderBy('kabupaten_nama')->get());
        $masterWilayah = collect([]);
        $psbPackage = null;
        if ($ticket->type === 'psb') {
            $paketName = $ticket->paket_layanan ?: $ticket->paket;
            if (!empty($paketName)) {
                $psbPackage = \App\Models\Paket::where('nama_paket', $paketName)
                    ->orWhere('mikrotik_profile', $paketName)
                    ->first();
            }
        }

        return view('ticket.show', compact('ticket', 'technicians', 'odps', 'olts', 'setting', 'pastTickets', 'warehouseItems', 'warehouseRequests', 'masterWilayah', 'masterProvinces', 'masterKabupatens', 'psbPackage'));
    }

    /**
     * NOC / Helpdesk Validation Action.
     */
    public function validateNoc(Request $request, int $id): RedirectResponse
    {
        $ticket = Ticket::findOrFail($id);
        $action = $request->input('action', 'valid'); // valid, self_resolved, invalid_cancel
        $catatan = $request->input('catatan_noc', '');

        $oldStatus = $ticket->status;

        if ($action === 'valid') {
            $ticket->status = 'ready_dispatch';
            $ticket->validated_by = Auth::id();
            $ticket->validated_at = now();
            $ticket->catatan_noc = $catatan;
            $ticket->save();

            $ticket->recordLog(
                action: 'Divalidasi NOC (Gangguan Fisik Valid)',
                fromStatus: $oldStatus,
                toStatus: 'ready_dispatch',
                notes: $catatan ?: 'NOC memvalidasi kendala dan meneruskan tiket ke Team Leader Teknisi.'
            );

            $msg = "✅ Tiket #{$ticket->ticket_number} berhasil divalidasi dan diteruskan ke Team Leader.";
        } elseif ($action === 'self_resolved') {
            $ticket->status = 'resolved';
            $ticket->validated_by = Auth::id();
            $ticket->validated_at = now();
            $ticket->resolved_at = now();
            $ticket->catatan_noc = $catatan;
            $ticket->catatan_teknisi = 'Diselesaikan langsung oleh NOC tanpa penugasan lapangan: ' . $catatan;
            $ticket->save();

            $ticket->recordLog(
                action: 'Diselesaikan Langsung oleh NOC',
                fromStatus: $oldStatus,
                toStatus: 'resolved',
                notes: $catatan ?: 'Gangguan terselesaikan jarak jauh oleh NOC.'
            );

            $msg = "🟢 Tiket #{$ticket->ticket_number} diselesaikan langsung oleh NOC.";
        } else {
            $ticket->status = 'cancelled';
            $ticket->validated_by = Auth::id();
            $ticket->validated_at = now();
            $ticket->catatan_noc = $catatan;
            $ticket->save();

            $ticket->recordLog(
                action: 'Tiket Dibatalkan oleh NOC (Invalid)',
                fromStatus: $oldStatus,
                toStatus: 'cancelled',
                notes: $catatan ?: 'Bukan kendala fisik / dibatalkan oleh NOC.'
            );

            $msg = "⚠️ Tiket #{$ticket->ticket_number} telah dibatalkan.";
        }

        ActivityLogService::log(
            'INFO',
            'Validasi NOC Tiket',
            "NOC memvalidasi {$ticket->ticket_number} (Aksi: {$action})",
            Auth::user()?->username ?? 'NOC'
        );

        return redirect()->route('ticket.show', $ticket->id)->with('sukses', $msg);
    }

    /**
     * Team Leader (TL) Dispatch & Assignment Action (Multi-Technician & Material Request).
     */
    public function dispatchTl(Request $request, int $id): RedirectResponse
    {
        $ticket = Ticket::findOrFail($id);

        $request->validate([
            'assigned_to'            => 'required|exists:users,id',
            'assigned_technicians'   => 'nullable|array',
            'assigned_technicians.*' => 'exists:users,id',
            'prioritas'              => 'nullable|in:low,normal,high,urgent',
            'catatan_tl'             => 'nullable|string|max:500',
        ]);

        $oldStatus = $ticket->status;
        $leadTech = User::findOrFail($request->input('assigned_to'));

        $teamTechIds = $request->input('assigned_technicians', []);
        if (!in_array($leadTech->id, $teamTechIds)) {
            array_unshift($teamTechIds, $leadTech->id);
        }
        $teamTechIds = array_values(array_unique(array_map('intval', $teamTechIds)));
        $allTechs = User::whereIn('id', $teamTechIds)->get();
        $allTechNames = $allTechs->pluck('nama')->toArray();
        $teamNamesStr = implode(', ', $allTechNames);

        $isRedispatch = !empty($ticket->assigned_to) && $ticket->assigned_to != $leadTech->id;
        $prevTech = $ticket->assigned_to ? User::find($ticket->assigned_to) : null;
        $prevTechName = $prevTech?->nama ?? 'Teknisi Sebelumnya';

        $ticket->assigned_to = $leadTech->id;
        $ticket->assigned_technicians = $teamTechIds;
        $ticket->assigned_by = Auth::id();
        $ticket->assigned_at = now();
        $ticket->status = 'assigned';
        if ($request->filled('prioritas')) {
            $ticket->prioritas = $request->input('prioritas');
        }
        if ($request->filled('catatan_tl')) {
            $ticket->catatan_tl = $request->input('catatan_tl');
        }
        $ticket->save();

        if ($isRedispatch) {
            $actionLog = "Disposisi Ulang ke Tim Teknisi ({$teamNamesStr})";
            $notesLog = $request->input('catatan_tl') ?: "Team Leader mengalihkan penugasan dari {$prevTechName} ke Tim: {$teamNamesStr}.";
        } else {
            $actionLog = count($teamTechIds) > 1 
                ? "Disposisi Tim Teknisi ({$teamNamesStr})" 
                : "Disposisi ke Teknisi ({$leadTech->nama})";
            $notesLog = $request->input('catatan_tl') ?: "Team Leader menugaskan tiket ke Tim: {$teamNamesStr}";
        }

        $ticket->recordLog(
            action: $actionLog,
            fromStatus: $oldStatus,
            toStatus: 'assigned',
            notes: $notesLog
        );

        // CREATE WAREHOUSE REQUEST FOR ANY TICKET TYPE IF MATERIALS ARE REQUESTED
        if ($request->filled('materials')) {
            $materials = $request->input('materials', []);
            $validItems = [];
            foreach ($materials as $m) {
                if (!empty($m['item_id']) && !empty($m['jumlah']) && (int)$m['jumlah'] > 0) {
                    $validItems[] = $m;
                }
            }

            if (!empty($validItems)) {
                $nomorRequest = 'REQ-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));
                $tipeRequest = match($ticket->type) {
                    'psb'       => 'psb_package',
                    'backbone'  => 'backbone_maintenance',
                    default     => 'trouble_ticket',
                };
                $alasan = match($ticket->type) {
                    'psb'       => "Paket Material PSB {$ticket->pelanggan_nama} ({$ticket->ticket_number}) untuk tim {$teamNamesStr}",
                    'backbone'  => "Material Perbaikan Insiden Backbone {$ticket->pelanggan_nama} ({$ticket->ticket_number}) untuk tim {$teamNamesStr}",
                    default     => "Material Perbaikan Tiket {$ticket->pelanggan_nama} ({$ticket->ticket_number}) untuk tim {$teamNamesStr}",
                };

                $whReq = \App\Models\WarehouseRequest::create([
                    'nomor_request' => $nomorRequest,
                    'tipe_request'  => $tipeRequest,
                    'ticket_id'     => $ticket->id,
                    'user_id'       => Auth::id(),
                    'divisi'        => 'teknisi',
                    'alasan'        => $alasan,
                    'status'        => 'pending_gudang',
                ]);

                foreach ($validItems as $vItem) {
                    $whItem = \App\Models\WarehouseItem::find($vItem['item_id']);
                    if ($whItem) {
                        \App\Models\WarehouseRequestItem::create([
                            'warehouse_request_id' => $whReq->id,
                            'warehouse_item_id'    => $whItem->id,
                            'jumlah_diminta'       => (int) $vItem['jumlah'],
                            'jumlah_disetujui'     => (int) $vItem['jumlah'],
                            'satuan'               => $whItem->satuan ?? 'Unit',
                            'catatan'              => $vItem['catatan'] ?? null,
                        ]);
                    }
                }

                $ticket->recordLog(
                    action: 'Pengajuan Material ke Gudang',
                    fromStatus: $oldStatus,
                    toStatus: 'assigned',
                    notes: "Team Leader mengajukan kebutuhan material ({$nomorRequest}) ke Gudang untuk tim teknisi {$teamNamesStr}."
                );
            }
        }

        ActivityLogService::log(
            'INFO',
            'Disposisi Tiket',
            $isRedispatch ? "TL mengalihkan penugasan {$ticket->ticket_number} ke Tim: {$teamNamesStr}" : "TL menugaskan {$ticket->ticket_number} ke Tim: {$teamNamesStr}",
            Auth::user()?->username ?? 'Team Leader'
        );

        // Auto Notify Customer WhatsApp (trouble tickets only)
        NotificationService::notifyCustomerTicketAssigned($ticket);

        // Auto Notify All Assigned Technicians via WhatsApp
        foreach ($allTechs as $tUser) {
            $ticketClone = clone $ticket;
            $ticketClone->assigned_to = $tUser->id;
            $ticketClone->setRelation('technician', $tUser);
            NotificationService::notifyTechnicianAssigned($ticketClone);
        }

        $targetName = match($ticket->type) {
            'psb'       => 'Pasang Baru',
            'backbone'  => 'Insiden Backbone',
            default     => 'Tiket Gangguan',
        };
        $msg = $isRedispatch 
            ? "🔄 Penugasan {$targetName} #{$ticket->ticket_number} berhasil dialihkan ke Tim: {$teamNamesStr}!" 
            : "🚀 {$targetName} #{$ticket->ticket_number} berhasil didisposisikan ke Tim: {$teamNamesStr}!";

        return redirect()->route('ticket.show', $ticket->id)->with('sukses', $msg);
    }

    /**
     * Technician In-Progress / OTW Update Action.
     */
    public function updateProgress(Request $request, int $id): RedirectResponse
    {
        $ticket = Ticket::findOrFail($id);
        $action = $request->input('action', 'in_progress'); // in_progress, pending_sparepart
        $notes = trim((string) $request->input('notes', ''));
        $kategoriKendala = $request->input('kategori_kendala', 'rumah_kosong');

        $oldStatus = $ticket->status;

        if ($action === 'in_progress') {
            $ticket->status = 'in_progress';
            if (!$ticket->in_progress_at) {
                $ticket->in_progress_at = now();
            }
            $ticket->save();

            $ticket->recordLog(
                action: 'Teknisi Sedang OTW / Tiba di Lokasi',
                fromStatus: $oldStatus,
                toStatus: 'in_progress',
                notes: $notes ?: 'Teknisi berangkat menuju titik lokasi pelanggan.'
            );

            ActivityLogService::log(
                'INFO',
                'Teknisi OTW / Progress',
                "Teknisi mengerjakan tiket #{$ticket->ticket_number}",
                Auth::user()?->username ?? 'Teknisi'
            );

            // Auto Notify Customer that tech is on the way
            NotificationService::notifyCustomerTechnicianOtw($ticket);

            $msg = "🚀 Status tiket #{$ticket->ticket_number} diperbarui: Teknisi sedang OTW / bekerja di lokasi.";
        } elseif ($action === 'submit_qc') {
            $request->validate([
                'biaya_pasang'      => 'nullable|numeric|min:0',
                'metode_pembayaran' => 'nullable|in:CASH,TRANSFER',
                'bukti_pembayaran'  => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
                'catatan_teknisi'   => 'nullable|string|max:500',
            ]);

            if ($request->input('metode_pembayaran') === 'TRANSFER' && !$request->hasFile('bukti_pembayaran') && empty($ticket->bukti_pembayaran)) {
                return redirect()->back()
                    ->withErrors(['bukti_pembayaran' => 'Lampiran bukti transfer wajib diunggah jika metode pembayaran TRANSFER.'])
                    ->withInput();
            }

            if ($request->filled('biaya_pasang')) {
                $ticket->biaya_pasang = (float) $request->input('biaya_pasang');
            }
            if ($request->filled('metode_pembayaran')) {
                $ticket->metode_pembayaran = strtoupper((string) $request->input('metode_pembayaran'));
                $ticket->payment_status = $ticket->metode_pembayaran === 'CASH'
                    ? 'pending_cash_settlement'
                    : 'pending_transfer_verification';
                $ticket->payment_notes = $ticket->metode_pembayaran === 'CASH'
                    ? 'Menunggu setor tunai ke Finance/Admin Kasir setelah pemasangan selesai.'
                    : 'Menunggu validasi lampiran transfer oleh Finance & Billing.';
            }
            if ($request->hasFile('bukti_pembayaran')) {
                $ticket->bukti_pembayaran = $request->file('bukti_pembayaran')->store('tickets/payments', 'public');
            }
            if ($request->filled('catatan_teknisi')) {
                $ticket->catatan_teknisi = trim((string) $request->input('catatan_teknisi'));
            }

            $ticket->status = 'resolved';
            $ticket->resolved_at = now();
            $ticket->save();

            // Sync payment info to DataSheet and Pelanggan
            if ($ticket->pelanggan_username) {
                \App\Models\DataSheet::where('username_pppoe', $ticket->pelanggan_username)->update([
                    'biaya_pasang' => $ticket->biaya_pasang,
                ]);
                \App\Models\Pelanggan::where('username', $ticket->pelanggan_username)->update([
                    'biaya_pasang' => $ticket->biaya_pasang,
                ]);
            }

            // Auto-sync full ticket data & photos to DataSheet and Google Sheet
            \App\Models\DataSheet::syncFromTicket($ticket);
            \App\Services\GoogleSheetSyncService::syncTicketToGoogleSheetAsync($ticket);

            $ticket->recordLog(
                action: 'Pemasangan Selesai Disubmit ke NOC & Finance',
                fromStatus: $oldStatus,
                toStatus: 'resolved',
                notes: 'Teknisi telah menyelesaikan konfigurasi perangkat, testing koneksi, dan rincian pembayaran di lokasi pelanggan. Mengajukan QC akhir ke Divisi NOC dan verifikasi pembayaran ke Finance Billing.'
            );

            ActivityLogService::log(
                'INFO',
                'Submit QC & Pembayaran PSB',
                "Pemasangan PSB {$ticket->ticket_number} ({$ticket->pelanggan_nama}) diserahkan ke NOC & Finance.",
                Auth::user()?->nama ?? 'Teknisi'
            );

            NotificationService::notifyNocTicketResolvedByTechnician($ticket);

            $msg = "📡 Laporan aktivasi & pembayaran berhasil disubmit! Tiket diteruskan ke Approval Finance Billing dan QC Akhir Divisi NOC.";
        } else {
            // Pending Sparepart / Kendala Lapangan / Rumah Kosong (Foto Bukti Lapangan)
            $request->validate([
                'foto_bukti'       => 'nullable|image|max:10240',
                'kategori_kendala' => 'required|string',
            ], [
                'foto_bukti.image' => 'File foto bukti harus berupa gambar.',
            ]);

            // Simpan foto bukti kunjungan/kendala
            if ($request->hasFile('foto_bukti')) {
                $ticket->foto_sebelum = $request->file('foto_bukti')->store('tickets/evidence', 'public');
            }

            $ticket->status = 'pending_sparepart';
            $ticket->save();

            $kategoriLabels = [
                'rumah_kosong' => '🏠 Rumah Kosong / Pelanggan Tidak Ada di Lokasi',
                'reschedule'   => '📅 Reschedule Jadwal',
                'sparepart'    => '⚙️ Menunggu Sparepart / Tangga Panjang',
                'lainnya'      => '⚠️ Kendala Lapangan',
            ];
            $kategoriText = $kategoriLabels[$kategoriKendala] ?? '⚠️ Kendala Lapangan';

            $ticket->recordLog(
                action: "Lapor Kendala: {$kategoriText}",
                fromStatus: $oldStatus,
                toStatus: 'pending_sparepart',
                notes: ($notes ?: 'Teknisi tiba di lokasi namun ada kendala lapangan.') . " (Foto bukti tersimpan)"
            );

            ActivityLogService::log(
                'WARNING',
                'Tiket Pending Kendala Lapangan',
                "Tiket #{$ticket->ticket_number} dipending oleh teknisi karena {$kategoriText}",
                Auth::user()?->username ?? 'Teknisi'
            );

            // Auto Notify Customer WhatsApp
            try {
                NotificationService::notifyCustomerPendingSparepart($ticket, $notes, $kategoriKendala);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Gagal mengirim WA pending kendala tiket #{$ticket->ticket_number}: " . $e->getMessage());
            }

            $msg = "⚠️ Tiket #{$ticket->ticket_number} dipending ({$kategoriText}). Foto bukti kunjungan tersimpan & laporan dikirim.";
        }

        if ($action === 'in_progress') {
            return redirect()->route('ticket.show', $ticket->id)->with('sukses', $msg);
        }

           return redirect()->back()->with('sukses', $msg);
    }

    /**
     * Technician Resolution & Completion Action.
     */
    public function resolve(Request $request, int $id): RedirectResponse
    {
        $ticket = Ticket::findOrFail($id);
        $isPsb = ($ticket->type === 'psb');
        $isDismantle = ($ticket->type === 'dismantle');

        $rules = [
            'redaman_sesudah'  => 'nullable|string|max:50',
            'catatan_teknisi'  => 'nullable|string|max:1000',
            'foto_sebelum'     => 'nullable|image|max:10240',
            'foto_sesudah'     => 'nullable|image|max:10240',
            'foto_rumah'       => 'nullable|image|max:10240',
            'foto_odp'         => 'nullable|image|max:10240',
            'foto_redaman'     => 'nullable|image|max:10240',
            'foto_label_kabel' => 'nullable|image|max:10240',
            'foto_dokumen'     => 'nullable|image|max:10240',
            'shareloc_url'     => 'nullable|string|max:500',
        ];

        // Khusus Cabut Alat (Dismantle): Wajib upload foto identitas alat (stiker SN/MAC/Modem) & rincian kelengkapan
        if ($isDismantle) {
            $rules['foto_sesudah'] = 'required|image|max:10240';
            $rules['kelengkapan_alat'] = 'required|string|max:255';
        } elseif (!$isPsb) {
            // Khusus Tiket Gangguan: Wajib upload Foto 1 (Bukti Titik Kendala / Kerusakan), Foto 2 (Hasil Perbaikan) bersifat opsional
            $rules['foto_sebelum'] = empty($ticket->foto_sebelum) ? 'required|image|max:10240' : 'nullable|image|max:10240';
        }

        $request->validate($rules, [
            'foto_sebelum.required'    => 'Wajib upload Foto Bukti Kendala / Titik Kerusakan (Foto 1)!',
            'foto_sebelum.image'       => 'Foto bukti kendala harus berupa file gambar/foto.',
            'foto_sesudah.required'    => 'Wajib upload foto identitas alat (stiker barcode SN / MAC / fisik modem & adaptor) yang dicabut!',
            'foto_sesudah.image'       => 'Foto bukti harus berupa file gambar/foto.',
            'kelengkapan_alat.required'=> 'Wajib mengisi rincian kelengkapan alat yang dicabut.',
        ]);

        $oldStatus = $ticket->status;

        // Upload Foto 1: Bukti Kendala / Titik Gangguan
        if ($request->hasFile('foto_sebelum')) {
            $ticket->foto_sebelum = $request->file('foto_sebelum')->store('tickets/evidence', 'public');
        }

        // Upload Foto 2: Completion Photo / Hasil Perbaikan / ONU Identity Photo
        if ($request->hasFile('foto_sesudah')) {
            $ticket->foto_sesudah = $request->file('foto_sesudah')->store('tickets/evidence', 'public');
        }

        // Upload Photo ODP
        if ($request->hasFile('foto_odp')) {
            $ticket->foto_odp = $request->file('foto_odp')->store('tickets/odp', 'public');
        }

        // Upload Photo Redaman OPM
        if ($request->hasFile('foto_redaman')) {
            $ticket->foto_redaman = $request->file('foto_redaman')->store('tickets/redaman', 'public');
        }

        // Upload Photo Label Kabel di ODP
        if ($request->hasFile('foto_label_kabel')) {
            $ticket->foto_label_kabel = $request->file('foto_label_kabel')->store('tickets/odp', 'public');
        }

        // Upload Dokumen Pemasangan / Berita Acara
        if ($request->hasFile('foto_dokumen')) {
            $ticket->foto_dokumen = $request->file('foto_dokumen')->store('tickets/docs', 'public');
        }

        // Upload / Update House Photo if provided
        if ($request->hasFile('foto_rumah')) {
            $ticket->foto_rumah = $request->file('foto_rumah')->store('tickets/houses', 'public');
        }

        // Customer ID & PPPoE Updates
        if ($request->filled('id_customer')) {
            $ticket->id_customer = trim($request->input('id_customer'));
        }
        if ($request->filled('pelanggan_username')) {
            $ticket->pelanggan_username = trim($request->input('pelanggan_username'));
        }
        if ($request->filled('pppoe_password')) {
            $ticket->pppoe_password = trim($request->input('pppoe_password'));
        }

        // Update Shareloc / Coordinates if provided
        if ($request->filled('shareloc_url')) {
            $ticket->shareloc_url = trim($request->input('shareloc_url'));
            $coords = $this->parseCoordinatesFromUrl($ticket->shareloc_url);
            if ($coords) {
                $ticket->latitude = $coords['lat'];
                $ticket->longitude = $coords['lng'];
            }
        }

        $ticket->catatan_teknisi = $request->input('catatan_teknisi') ?: ($isPsb ? 'Pemasangan Pasang Baru (PSB) selesai di lokasi pelanggan.' : 'Pengerjaan selesai.');
        $ticket->redaman_sebelum = $request->input('redaman_sebelum');
        $ticket->redaman_sesudah = $request->input('redaman_sesudah');
        if ($request->filled('serial_number_ont')) $ticket->serial_number_ont = strtoupper(trim($request->input('serial_number_ont')));
        if ($request->filled('pon_sn')) $ticket->pon_sn = strtoupper(trim($request->input('pon_sn')));
        if ($request->filled('mac_ont')) {
            $ticket->mac_ont = strtoupper(str_replace([':', '-', ' '], '', trim($request->input('mac_ont'))));
        } elseif (!empty($ticket->pon_sn)) {
            $p = $ticket->pon_sn;
            if (str_starts_with($p, 'VSOL') && strlen($p) >= 10) {
                $ticket->mac_ont = '4C46D1' . substr($p, -6);
            } elseif (str_starts_with($p, 'HWTC') && strlen($p) >= 10) {
                $ticket->mac_ont = '001882' . substr($p, -6);
            } elseif (str_starts_with($p, 'ZTEG') && strlen($p) >= 10) {
                $ticket->mac_ont = '001E73' . substr($p, -6);
            }
        }
        if ($request->filled('port_odp')) $ticket->port_odp = trim($request->input('port_odp'));
        if ($request->filled('panjang_kabel')) $ticket->panjang_kabel = $request->input('panjang_kabel');

        // IF DISMANTLE: Create Warehouse Return Queue & Hold for Warehouse Verification
        if ($isDismantle) {
            $ticket->status = 'pending_gudang'; // Menunggu konfirmasi fisik dari Kepala Gudang
            if ($request->filled('kelengkapan_alat')) {
                $ticket->kelengkapan_alat = $request->input('kelengkapan_alat');
            }
            $ticket->save();

            // Disable MikroTik PPPoE Secret & Kick active session immediately
            $mtResult = ['success' => false, 'message' => 'Username PPPoE tidak ditemukan.'];
            if (!empty($ticket->pelanggan_username)) {
                try {
                    $mtService = new \App\Services\MikrotikService();
                    $mtResult = $mtService->disablePppoeSecret($ticket->pelanggan_username);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("MikroTik disable secret on dismantle failed: " . $e->getMessage());
                }
            }

            // AUTO-CREATE WAREHOUSE RETURN ENTRY
            $nomorRetur = 'RET-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));
            \App\Models\WarehouseReturn::create([
                'nomor_retur'     => $nomorRetur,
                'ticket_id'       => $ticket->id,
                'teknisi_id'      => Auth::id(),
                'pelanggan_nama'  => $ticket->pelanggan_nama,
                'nama_barang'     => 'Perangkat Cabut: ' . ($ticket->kelengkapan_alat ?? 'Modem ONU + Adaptor'),
                'serial_number'   => $ticket->serial_number_ont ?? '-',
                'mac_address'     => $ticket->mac_ont ?? '-',
                'kondisi'         => 'layak_pakai',
                'foto_barang'     => $ticket->foto_sesudah,
                'status'          => 'pending_gudang',
                'catatan_teknisi' => ($ticket->catatan_teknisi ?: 'Cabut alat di lokasi') . ' | Kelengkapan: ' . ($ticket->kelengkapan_alat ?? '-'),
            ]);

            $ticket->recordLog(
                action: 'Cabut Alat Diserahkan ke Antrean Retur Gudang',
                fromStatus: $oldStatus,
                toStatus: 'pending_gudang',
                notes: "Perangkat berhasil dicabut di lapangan (Retur: {$nomorRetur}). Menunggu verifikasi penerimaan fisik oleh Kepala Gudang. " . ($mtResult['message'] ?? '')
            );

            // Auto Sync to DataSheet & Google Sheet (Tab CABUT ALAT)
            \App\Models\DataSheet::syncFromTicket($ticket);
            \App\Services\GoogleSheetSyncService::syncTicketToGoogleSheetAsync($ticket);

            return redirect()->route('ticket.show', $ticket->id)
                             ->with('sukses', "📦 Laporan Cabut Alat #{$ticket->ticket_number} ({$ticket->pelanggan_nama}) berhasil disubmit! Antrean Retur ({$nomorRetur}) dibuat.");
        }

        // IF PSB: Physical installation report submitted -> Send request to NOC for VLAN allocation
        if ($isPsb) {
            $ticket->status = 'pending_noc';
            $ticket->request_vlan_at = now();
            $ticket->save();

            // Auto-sync photos & location to DataSheet immediately so photos are preserved in DataSheet
            \App\Models\DataSheet::syncFromTicket($ticket);
            \App\Services\GoogleSheetSyncService::syncTicketToGoogleSheetAsync($ticket);

            $ticket->recordLog(
                action: 'Laporan Lapangan Disubmit & Request Alokasi VLAN ke Divisi NOC',
                fromStatus: $oldStatus,
                toStatus: 'pending_noc',
                notes: "Teknisi selesai pemasangan fisik (MAC: {$ticket->mac_ont}, PON SN: {$ticket->pon_sn}, SN: {$ticket->serial_number_ont}). Mengajukan permohonan alokasi VLAN ke Divisi NOC."
            );

            ActivityLogService::log(
                'INFO',
                'Request VLAN PSB ke NOC',
                "Teknisi melaporkan instalasi fisik PSB {$ticket->ticket_number} dan meminta alokasi VLAN ke NOC.",
                Auth::user()?->nama ?? 'Teknisi'
            );

            NotificationService::notifyNocTicketResolvedByTechnician($ticket);

            return redirect()->route('ticket.show', $ticket->id)
                ->with('sukses', "📡 Laporan fisik pemasangan #{$ticket->ticket_number} ({$ticket->pelanggan_nama}) berhasil disubmit! Tiket diteruskan ke Divisi NOC untuk alokasi VLAN.");
        }

        // IF TROUBLE TICKET: Move to resolved (Awaiting NOC final verification)
        $ticket->status = 'resolved';
        $ticket->resolved_at = now();
        $ticket->save();

        // Auto-sync trouble ticket photos & data to DataSheet
        \App\Models\DataSheet::syncFromTicket($ticket);
        \App\Services\GoogleSheetSyncService::syncTicketToGoogleSheetAsync($ticket);

        $pppoeInfo = $ticket->pelanggan_username ? " [PPPoE: {$ticket->pelanggan_username}]" : "";
        $ticket->recordLog(
            action: 'Laporan Lapangan Disubmit (Menunggu Verifikasi Akhir NOC)',
            fromStatus: $oldStatus,
            toStatus: 'resolved',
            notes: "Perbaikan teknisi selesai. Redaman: {$ticket->redaman_sesudah} dBm. Port ODP: {$ticket->port_odp}. Menunggu validasi penutupan tiket oleh Divisi NOC."
        );

        ActivityLogService::log(
            'SUCCESS',
            'Lapor Selesai Tiket',
            "Teknisi melaporkan perbaikan tiket {$ticket->ticket_number} selesai (Redaman: {$ticket->redaman_sesudah} dBm){$pppoeInfo}",
            Auth::user()?->nama ?? (Auth::user()?->username ?? 'Teknisi')
        );

        // Auto Notify NOC that ticket is resolved and ready for closure
        NotificationService::notifyNocTicketResolvedByTechnician($ticket);

        return redirect()->route('ticket.show', $ticket->id)
                         ->with('sukses', "✅ Laporan pengerjaan #{$ticket->ticket_number} ({$ticket->pelanggan_nama}) berhasil dikirim! Menunggu validasi akhir NOC untuk penutupan resmi.");
    }

    /**
     * Final Verification & Closure Action.
     */
    public function close(Request $request, int $id): RedirectResponse
    {
        $ticket = Ticket::findOrFail($id);
        $user = Auth::user();
        $isPsb = ($ticket->type === 'psb');
        $oldStatus = $ticket->status;

        $ticket->status = 'closed';
        $ticket->closed_by = Auth::id();
        $ticket->closed_at = now();
        $ticket->save();

        if ($isPsb) {
            $desaKode = $ticket->desa_kode ?: '1803100014';
            $wilayahRow = \App\Models\MasterWilayah::where('desa_kode', $desaKode)->orWhere('kode_wilayah_full', $desaKode)->first();
            $fullWilayahKode = $wilayahRow?->kode_wilayah_full ?? (strlen($desaKode) >= 10 ? $desaKode : '1803100013');

            if (!empty($ticket->pelanggan_username)) {
                \App\Models\Pelanggan::updateOrCreate(
                    ['username' => $ticket->pelanggan_username],
                    [
                        'id_customer'        => $ticket->id_customer,
                        'nama'               => $ticket->pelanggan_nama,
                        'nama_depan'         => $ticket->nama_depan,
                        'nama_belakang'      => $ticket->nama_belakang,
                        'provinsi'           => $wilayahRow?->provinsi_nama ?? 'Lampung',
                        'kabupaten'          => $wilayahRow?->kabupaten_nama ?? 'Lampung Selatan',
                        'kecamatan'          => $wilayahRow?->kecamatan_nama ?? 'Sidomulyo',
                        'desa'               => $wilayahRow?->desa_nama ?? 'Sidorejo',
                        'kode_wilayah'       => $fullWilayahKode,
                        'paket'              => $ticket->paket_layanan ?? ($ticket->paket ?? 'Reguler'),
                        'ip'                 => '10.0.0.1',
                        'status'             => 'Online',
                        'password_pppoe'     => $ticket->pppoe_password ?? '1',
                        'vlan'               => $ticket->vlan,
                        'mac_address'        => $ticket->mac_ont,
                        'pon_sn'             => $ticket->pon_sn,
                        'serial_number'      => $ticket->serial_number_ont,
                        'foto_odp'           => $ticket->foto_odp,
                        'foto_redaman'       => $ticket->foto_redaman,
                        'foto_label_kabel'   => $ticket->foto_label_kabel,
                        'foto_dokumen'       => $ticket->foto_dokumen,
                        'foto_identitas_onu' => $ticket->foto_sesudah,
                    ]
                );

                // AUTOMATICALLY CREATE PPPOE SECRET IN MIKROTIK ROUTER!
                try {
                    $mikrotikService = new \App\Services\MikrotikService();
                    $paketVal = $ticket->paket ?? ($ticket->paket_layanan ?? '');
                    $paketObj = \App\Models\Paket::where('nama_paket', $paketVal)
                        ->orWhere('mikrotik_profile', $paketVal)
                        ->orWhere('nama_paket', 'like', "%{$paketVal}%")
                        ->orWhere('mikrotik_profile', 'like', "%{$paketVal}%")
                        ->first();
                    $profile = $paketObj?->mikrotik_profile ?? ($paketVal ?: 'default');
                    $mikrotikService->createOrUpdatePppoeSecret(
                        username: $ticket->pelanggan_username,
                        password: $ticket->pppoe_password ?? '1',
                        profile: $profile,
                        service: 'pppoe',
                        comment: ''
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("Auto create PPPoE secret on MikroTik failed: " . $e->getMessage());
                }
            }

            $actionLabel = 'Pasang Baru (PSB) Selesai Tuntas & Di-Approve QC by NOC';
            $actorTitle = $user->nama ?? 'NOC / Admin';
            $successMsg = "🎉 Pasang Baru #{$ticket->ticket_number} ({$ticket->pelanggan_nama}) RESMI DI-APPROVE QC! Secret PPPoE otomatis dibuat di MikroTik, Foto Rumah/ODP tersimpan di DataSheet & Pelanggan PPPoE.";
        } elseif ($ticket->type === 'dismantle') {
            if (!empty($ticket->pelanggan_username)) {
                try {
                    $mikrotikService = new \App\Services\MikrotikService();
                    $mikrotikService->removePppoeSecret($ticket->pelanggan_username);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("MikroTik remove secret on dismantle closed failed: " . $e->getMessage());
                }
            }

            if (!empty($ticket->pelanggan_username) || !empty($ticket->id_customer)) {
                $pelanggan = \App\Models\Pelanggan::where('username', $ticket->pelanggan_username)
                    ->orWhere('id_customer', $ticket->id_customer)
                    ->first();
                if ($pelanggan) {
                    $pelanggan->update([
                        'status' => 'Cabut Alat',
                    ]);
                }
            }

            $actionLabel = 'Tiket Cabut Alat Selesai Tuntas (CLOSED)';
            $actorTitle = $user->nama ?? 'Kepala Gudang / Admin';
            $successMsg = "📦 Tiket Cabut Alat #{$ticket->ticket_number} ({$ticket->pelanggan_nama}) RESMI DITUTUP (CLOSED)! Secret PPPoE dihapus permanen dari MikroTik dan status pelanggan dialihkan ke Cabut Alat.";
        } else {
            $actionLabel = 'Tiket Gangguan Diverifikasi Tuntas & Selesai Resmi by NOC (DONE)';
            $actorTitle = $user->nama ?? 'NOC / Admin';
            $successMsg = "🔒 Tiket Gangguan #{$ticket->ticket_number} RESMI DIVERIFIKASI TUNTAS BY NOC! Pemberitahuan otomatis dikirim ke WhatsApp & Web CS.";
        }

        $ticket->recordLog(
            action: $actionLabel,
            fromStatus: $oldStatus,
            toStatus: 'closed',
            notes: "Tiket {$ticket->type} telah divalidasi tuntas oleh {$actorTitle} dan status telah disinkronkan ke Customer Service."
        );

        ActivityLogService::log(
            'SUCCESS',
            $isPsb ? 'PSB Closed (NOC QC)' : 'Tiket Closed (NOC)',
            "{$actorTitle} menyelesaikan {$ticket->ticket_number} ({$ticket->pelanggan_nama})",
            $user->username ?? 'System'
        );

        // Notify Customer via WhatsApp & Team via Telegram
        NotificationService::notifyCustomerTicketResolved($ticket);
        NotificationService::notifyCsTicketDone($ticket);

        // Auto Sync to DataSheet & Google Sheet without creating duplicates
        \App\Models\DataSheet::syncFromTicket($ticket);
        \App\Services\GoogleSheetSyncService::syncTicketToGoogleSheetAsync($ticket);

        // Redirect back to Meja Kerja (Workspace) so NOC can immediately process the next ticket in queue
        if ($isPsb) {
            return redirect()->route('psb.index')
                ->with('sukses', $successMsg)
                ->with('qc_celebration', true)
                ->with('qc_ticket_number', $ticket->ticket_number)
                ->with('qc_pelanggan_nama', $ticket->pelanggan_nama)
                ->with('qc_is_psb', true);
        }

        return redirect()->route('ticket.index')
            ->with('sukses', $successMsg)
            ->with('qc_celebration', true)
            ->with('qc_ticket_number', $ticket->ticket_number)
            ->with('qc_pelanggan_nama', $ticket->pelanggan_nama)
            ->with('qc_is_psb', false);
    }

    /**
     * Helper to parse latitude and longitude from Google Maps URL or query string.
     */
    protected function parseCoordinatesFromUrl(string $url): ?array
    {
        try {
            // Check for @lat,lng format
            if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
                return ['lat' => (float)$m[1], 'lng' => (float)$m[2]];
            }
            // Check for q=lat,lng format
            if (preg_match('/q=(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
                return ['lat' => (float)$m[1], 'lng' => (float)$m[2]];
            }
            // Check for raw lat,lng string
            if (preg_match('/^\s*(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)\s*$/', $url, $m)) {
                return ['lat' => (float)$m[1], 'lng' => (float)$m[2]];
            }
        } catch (Throwable $e) {}

        return null;
    }

    /**
     * Divisi NOC assigns VLAN to PSB ticket and generates customer credentials.
     */
    public function assignVlanNoc(Request $request, int $id): RedirectResponse
    {
        $ticket = Ticket::findOrFail($id);

        $request->validate([
            'vlan'        => 'required|string|max:50',
            'catatan_noc' => 'nullable|string|max:500',
        ]);

        $oldStatus = $ticket->status;
        $paketName = $ticket->paket_layanan ?: $ticket->paket;
        $paket = null;
        if (!empty($paketName)) {
            $paket = \App\Models\Paket::where('nama_paket', $paketName)
                ->orWhere('mikrotik_profile', $paketName)
                ->first();
        }

        $ticket->vlan = trim($request->input('vlan'));
        $ticket->noc_assigned_vlan_by = Auth::id();
        $ticket->noc_assigned_vlan_at = now();
        if ($paket) {
            $ticket->harga_paket = $paket->tarif_bulanan;
        }
        if ($request->filled('catatan_noc')) {
            $ticket->catatan_noc = trim($request->input('catatan_noc'));
        }

        // Auto generate ID Customer cleanly (10-digit BPS code + 4-digit sequence = 14 digits)
        $desaKode = $ticket->desa_kode ?: '1803100014';
        $wilayahRow = \App\Models\MasterWilayah::where('desa_kode', $desaKode)->orWhere('kode_wilayah_full', $desaKode)->first();
        $fullWilayahKode = $wilayahRow?->kode_wilayah_full ?? (strlen($desaKode) >= 10 ? $desaKode : '1803100013');

        if (empty($ticket->id_customer) || strlen($ticket->id_customer) > 14) {
            $ticket->id_customer = \App\Models\MasterWilayah::generateCustomerId($fullWilayahKode);
        }

        $namaSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ticket->nama_depan ?: explode(' ', $ticket->pelanggan_nama)[0]));
        $ticket->pelanggan_username = $namaSlug ? "{$ticket->id_customer}@{$namaSlug}" : "{$ticket->id_customer}@user";
        $ticket->pppoe_password = '1';

        // Move to ready_activation (Data Teknis Lengkap - Siap Aktivasi & Testing Lapangan)
        $ticket->status = 'ready_activation';
        $ticket->save();

        // 1. Auto Sync/Create Pelanggan in Database
        if (!empty($ticket->pelanggan_username)) {
            \App\Models\Pelanggan::updateOrCreate(
                ['username' => $ticket->pelanggan_username],
                [
                    'id_customer'        => $ticket->id_customer,
                    'nama'               => $ticket->pelanggan_nama,
                    'kategori_pelanggan' => $ticket->kategori_pelanggan ?: 'MR',
                    'nama_depan'         => $ticket->nama_depan,
                    'nama_belakang'      => $ticket->nama_belakang,
                    'provinsi'           => $wilayahRow?->provinsi_nama ?? 'Lampung',
                    'kabupaten'          => $wilayahRow?->kabupaten_nama ?? 'Lampung Selatan',
                    'kecamatan'          => $wilayahRow?->kecamatan_nama ?? 'Sidomulyo',
                    'desa'               => $wilayahRow?->desa_nama ?? 'Sidorejo',
                    'kode_wilayah'       => $fullWilayahKode,
                    'paket'              => $ticket->paket_layanan ?? ($ticket->paket ?? 'Reguler'),
                    'ip'                 => '10.0.0.1',
                    'status'             => 'Online',
                    'password_pppoe'     => $ticket->pppoe_password ?? '1',
                    'vlan'               => $ticket->vlan,
                    'mac_address'        => $ticket->mac_ont,
                    'pon_sn'             => $ticket->pon_sn,
                    'serial_number'      => $ticket->serial_number_ont,
                    'foto_odp'           => $ticket->foto_odp,
                    'foto_redaman'       => $ticket->foto_redaman,
                    'foto_label_kabel'   => $ticket->foto_label_kabel,
                    'foto_dokumen'       => $ticket->foto_dokumen,
                    'foto_identitas_onu' => $ticket->foto_sesudah,
                    'harga_paket'        => $ticket->harga_paket,
                    'biaya_pasang'       => $ticket->biaya_pasang,
                ]
            );

            \App\Models\DataSheet::where('username_pppoe', $ticket->pelanggan_username)->update([
                'harga_paket' => $ticket->harga_paket,
                'biaya_pasang' => $ticket->biaya_pasang,
            ]);

            // 2. AUTOMATICALLY INSTALL / PROVISION PPPOE SECRET ON MIKROTIK ROUTER!
            try {
                $mikrotikService = new \App\Services\MikrotikService();
                $paketVal = $ticket->paket ?? ($ticket->paket_layanan ?? '');
                $paketObj = \App\Models\Paket::where('nama_paket', $paketVal)
                    ->orWhere('mikrotik_profile', $paketVal)
                    ->orWhere('nama_paket', 'like', "%{$paketVal}%")
                    ->orWhere('mikrotik_profile', 'like', "%{$paketVal}%")
                    ->first();
                $profile = $paketObj?->mikrotik_profile ?? ($paketVal ?: 'default');
                $mikrotikService->createOrUpdatePppoeSecret(
                    username: $ticket->pelanggan_username,
                    password: $ticket->pppoe_password ?? '1',
                    profile: $profile,
                    service: 'pppoe',
                    comment: ''
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Auto create PPPoE secret on MikroTik in assignVlanNoc failed: " . $e->getMessage());
            }
        }

        // Auto-sync full ticket data & photos to DataSheet and Google Sheet
        \App\Models\DataSheet::syncFromTicket($ticket);
        \App\Services\GoogleSheetSyncService::syncTicketToGoogleSheetAsync($ticket);

        $ticket->recordLog(
            action: 'NOC Mengalokasikan VLAN & Mengaktivasi di MikroTik',
            fromStatus: $oldStatus,
            toStatus: 'ready_activation',
            notes: "VLAN dialokasikan: {$ticket->vlan}. Data akun pelanggan diterbitkan & secret otomatis diinstal ke MikroTik (ID Customer: {$ticket->id_customer} | PPPoE: {$ticket->pelanggan_username} | Password: 1). Pembayaran PSB: paket Rp " . number_format((float) $ticket->harga_paket, 0, ',', '.') . ", pasang Rp " . number_format((float) $ticket->biaya_pasang, 0, ',', '.') . ", metode {$ticket->metode_pembayaran}. Status tiket masuk ke tahap Siap Aktivasi & Testing Lapangan."
        );

        ActivityLogService::log(
            'INFO',
            'Alokasi VLAN & Aktivasi MikroTik PSB',
            "NOC mengalokasikan VLAN {$ticket->vlan} dan membuat secret MikroTik untuk PSB {$ticket->ticket_number} (ID: {$ticket->id_customer})",
            Auth::user()?->nama ?? 'NOC'
        );

        return redirect()->route('ticket.show', $ticket->id)
            ->with('sukses', "💾 VLAN [{$ticket->vlan}] & Secret PPPoE [{$ticket->pelanggan_username}] berhasil diaktivasi di MikroTik untuk pelanggan {$ticket->pelanggan_nama}!");
    }

    /**
     * Delete Ticket / PSB (Strictly restricted to NOC / Akun Utama Administrator).
     */
    public function destroy(Request $request, int $id)
    {
        $ticket = Ticket::findOrFail($id);
        $user = Auth::user();
        if ($ticket->type === 'psb' && !$user->isSuperAdmin()) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak. Hanya superadmin yang dapat menghapus registrasi pelanggan baru.'], 403);
            }
            return redirect()->back()
                             ->with('error', 'Akses ditolak. Hanya superadmin yang dapat menghapus registrasi pelanggan baru.');
        }
        if ($ticket->type !== 'psb' && $user->role !== 'admin' && !$user->hasPermission('tiket_noc') && $user->role !== 'noc') {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => '⛔ Akses ditolak! Hanya Akun Utama Administrator atau Tim NOC yang memiliki wewenang menghapus data tiket/PSB.'], 403);
            }
            return redirect()->back()
                             ->with('error', '⛔ Akses ditolak! Hanya Akun Utama Administrator atau Tim NOC yang memiliki wewenang menghapus data tiket/PSB.');
        }

        $num = $ticket->ticket_number;
        $name = $ticket->pelanggan_nama;
        $type = $ticket->type;

        // Delete uploaded files if exist
        if ($ticket->foto_rumah && Storage::disk('public')->exists($ticket->foto_rumah)) {
            Storage::disk('public')->delete($ticket->foto_rumah);
        }
        if ($ticket->foto_sebelum && Storage::disk('public')->exists($ticket->foto_sebelum)) {
            Storage::disk('public')->delete($ticket->foto_sebelum);
        }
        if ($ticket->foto_sesudah && Storage::disk('public')->exists($ticket->foto_sesudah)) {
            Storage::disk('public')->delete($ticket->foto_sesudah);
        }
        if ($ticket->bukti_pembayaran && Storage::disk('public')->exists($ticket->bukti_pembayaran)) {
            Storage::disk('public')->delete($ticket->bukti_pembayaran);
        }

        // Delete logs
        $ticket->logs()->delete();

        // Delete ticket
        $ticket->delete();

        ActivityLogService::log(
            'WARNING',
            'Hapus Tiket',
            "Administrator/NOC menghapus {$type} #{$num} ({$name})",
            $user->nama ?? ($user->username ?? 'Admin')
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => "🗑️ Data {$type} #{$num} ({$name}) berhasil dihapus permanen dari sistem."
            ]);
        }

        // If user deleted from show page, fallback to list page
        $referer = request()->headers->get('referer');
        if ($referer && str_contains($referer, '/tiket/' . $id)) {
            return redirect()->route($type === 'psb' ? 'psb.index' : 'ticket.index')
                             ->with('sukses', "🗑️ Data {$type} #{$num} ({$name}) berhasil dihapus permanen dari sistem.");
        }

        return redirect()->back()
                         ->with('sukses', "🗑️ Data {$type} #{$num} ({$name}) berhasil dihapus permanen dari sistem.");
    }

    /**
     * High-Precision Server-Side OCR for ONU Identity Sticker.
     */
    public function scanOnuPhoto(Request $request)
    {
        $request->validate([
            'foto_sesudah' => 'required|file|image|max:20480',
        ]);

        try {
            $file = $request->file('foto_sesudah');
            $tempPath = $file->getRealPath();

            $scriptPath = base_path('scripts/ocr_scan.js');
            $command = 'node ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($tempPath);

            $output = shell_exec($command);
            $result = json_decode($output, true);

            if ($result && ($result['success'] ?? false)) {
                return response()->json([
                    'success'           => true,
                    'mac'               => $result['mac'] ?? '',
                    'pon_sn'            => $result['pon_sn'] ?? '',
                    'serial_number_ont' => $result['serial_number_ont'] ?? '',
                    'raw_text'          => $result['raw_text'] ?? '',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal membaca teks stiker ONU.',
                'raw'     => $output,
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server OCR Exception: ' . $e->getMessage(),
            ], 500);
        }
    }

    protected function appendPsbActionNote(Ticket $ticket, string $label, string $catatan): void
    {
        if ($catatan === '') {
            return;
        }

        $targetField = Schema::hasColumn('tickets', 'catatan_cs') ? 'catatan_cs' : 'deskripsi_keluhan';
        $currentValue = trim((string) ($ticket->{$targetField} ?? ''));
        $ticket->{$targetField} = trim($currentValue . ($currentValue !== '' ? "\n" : '') . "[{$label}] {$catatan}");
    }

    /**
     * Export Tickets / PSB / Dismantle data to CSV/Excel with date range and status filters.
     */
    public function export(Request $request)
    {
        $ticketType = $request->query('ticket_type', 'all'); // all, psb, trouble, dismantle
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $status = $request->query('status', 'all');
        $assignedTo = $request->query('assigned_to', 'all');
        $format = $request->query('format', 'excel'); // excel (.xls) or csv (.csv)

        $typeLabel = match($ticketType) {
            'psb'       => 'Pasang_Baru_PSB',
            'trouble'   => 'Tiket_Gangguan',
            'dismantle' => 'Cabut_Alat_Dismantle',
            default     => 'Semua_Tiket',
        };

        $dateSuffix = ($startDate ?: 'Awal') . '_sd_' . ($endDate ?: 'Sekarang');
        $filenameBase = "Rekap_Tiket_{$typeLabel}_{$dateSuffix}";
        $sheetTitle = substr("Tiket_" . $typeLabel, 0, 31);

        $headers = [
            'No',
            'No. Tiket',
            'Tipe Pekerjaan',
            'Kategori Keluhan / Layanan',
            'Nama Pelanggan',
            'Username PPPoE',
            'ID Customer / NIK',
            'No. WhatsApp / Telp',
            'No. Alternatif',
            'Alamat Lengkap',
            'Patokan Alamat',
            'Paket Layanan',
            'Nama ODP',
            'Port ODP',
            'Teknisi Bertugas (PIC)',
            'Status Tiket',
            'Prioritas',
            'Biaya PSB / Registrasi (Rp)',
            'Status Bayar PSB',
            'Metode Bayar',
            'Redaman Sebelum (dBm)',
            'Redaman Sesudah (dBm)',
            'Panjang Kabel (m)',
            'Serial Number (SN ONT)',
            'MAC ONT / Modem',
            'Nama Marketing / Sales',
            'Tanggal Dibuat',
            'Tanggal Penugasan TL',
            'Tanggal Selesai',
            'Deskripsi Masalah / Catatan CS',
            'Solusi / Catatan Lapangan Teknisi',
        ];

        $query = Ticket::with(['technician', 'odp'])->latest('id');

        if ($ticketType && $ticketType !== 'all') {
            $query->where('type', $ticketType);
        }
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }
        if ($assignedTo && $assignedTo !== 'all') {
            $query->where('assigned_to', $assignedTo);
        }

        $tickets = $query->get();
        $rows = [];
        $no = 1;
        foreach ($tickets as $t) {
            $tglDibuat = $t->created_at ? $t->created_at->format('d/m/Y H:i') : '-';
            $tglDispatched = $t->dispatched_at ? $t->dispatched_at->format('d/m/Y H:i') : '-';
            $tglSelesai = $t->resolved_at ? $t->resolved_at->format('d/m/Y H:i') : ($t->validated_at ? $t->validated_at->format('d/m/Y H:i') : '-');
            $biaya = $t->payment_amount ? (int)$t->payment_amount : ($t->biaya_registrasi ? (int)$t->biaya_registrasi : 0);

            $rows[] = [
                $no++,
                $t->ticket_number,
                $t->type_label,
                $t->kategori_gangguan ?: ($t->kategori_pelanggan ?: '-'),
                $t->pelanggan_nama,
                $t->pelanggan_username ?: '-',
                $t->id_customer ?: '-',
                $t->pelanggan_telepon ?: '-',
                $t->pelanggan_telepon_cadangan ?: '-',
                $t->pelanggan_alamat ?: ($t->alamat ?: '-'),
                $t->patokan_alamat ?: '-',
                $t->paket_layanan ?: '-',
                $t->odp?->nama_odp ?? ($t->nama_odp ?: '-'),
                $t->port_odp ?: '-',
                $t->technician?->nama ?? 'Belum Ditugaskan',
                $t->status_label,
                strtoupper($t->priority ?? 'normal'),
                $biaya,
                strtoupper(str_replace('_', ' ', $t->payment_status ?? 'pending')),
                strtoupper($t->payment_method ?? 'CASH'),
                $t->redaman_sebelum ?: '-',
                $t->redaman_sesudah ?: '-',
                $t->panjang_kabel ? $t->panjang_kabel . ' m' : '-',
                $t->serial_number_ont ?: '-',
                $t->mac_ont ?: '-',
                $t->nama_marketing ?: '-',
                $tglDibuat,
                $tglDispatched,
                $tglSelesai,
                $t->deskripsi_masalah ?: ($t->catatan_cs ?: '-'),
                $t->catatan_teknisi ?: '-',
            ];
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
