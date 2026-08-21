<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\InterDivisionTask;
use App\Models\ProcurementRequest;
use App\Models\TroubleTicket;
use App\Models\WorkOrder;

class DashboardService
{
    public function summary(): array
    {
        return [
            'metrics' => [
                'customers' => Customer::query()->count(),
                'activeCustomers' => Customer::query()->where('status', 'active')->count(),
                'openTickets' => TroubleTicket::query()->whereNotIn('status', ['closed', 'cancelled'])->count(),
                'pendingWorkOrders' => WorkOrder::query()->whereNotIn('status', ['completed'])->count(),
                'pendingProcurements' => ProcurementRequest::query()->whereIn('status', ['pending_finance', 'pending_management'])->count(),
                'openTasks' => InterDivisionTask::query()->where('status', '!=', 'done')->count(),
            ],
            'ticketsByStatus' => TroubleTicket::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'procurementsByStatus' => ProcurementRequest::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ];
    }
}
