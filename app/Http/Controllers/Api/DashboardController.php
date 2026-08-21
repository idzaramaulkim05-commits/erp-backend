<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\ProcurementRequestResource;
use App\Http\Resources\TicketResource;
use App\Models\AuditLog;
use App\Models\ProcurementRequest;
use App\Models\TroubleTicket;
use App\Services\DashboardService;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function index()
    {
        return response()->json([
            ...$this->dashboard->summary(),
            'latestTickets' => TicketResource::collection(TroubleTicket::query()->latest()->limit(5)->get()),
            'latestProcurements' => ProcurementRequestResource::collection(ProcurementRequest::query()->latest()->limit(5)->get()),
            'latestAuditLogs' => AuditLogResource::collection(AuditLog::query()->orderByDesc('timestamp')->limit(10)->get()),
        ]);
    }
}
