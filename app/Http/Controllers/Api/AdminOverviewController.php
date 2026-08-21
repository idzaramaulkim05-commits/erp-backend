<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminOverviewResource;
use App\Models\AdminMasterDataGroup;
use App\Models\AuditLog;
use App\Models\User;

class AdminOverviewController extends Controller
{
    public function index()
    {
        $groups = AdminMasterDataGroup::query()->get()->keyBy('key');

        return AdminOverviewResource::make([
            'totalUsers' => User::query()->count(),
            'activeUsers' => User::query()->where('is_active', true)->count(),
            'inactiveUsers' => User::query()->where('is_active', false)->count(),
            'onlineUsers' => User::query()->where('is_online', true)->count(),
            'auditCount' => AuditLog::query()->count(),
            'masterDataGroupCount' => $groups->count(),
            'servicePackageCount' => count($groups->get('service_packages')?->items ?? []),
            'regionCount' => count($groups->get('regions')?->items ?? []),
            'inventoryReferenceCount' => count($groups->get('inventory_references')?->items ?? []),
            'workflowReferenceCount' => count($groups->get('workflow_references')?->items ?? []),
            'latestAuditLogs' => AuditLog::query()->orderByDesc('timestamp')->limit(8)->get(),
        ]);
    }
}
