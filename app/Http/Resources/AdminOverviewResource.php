<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminOverviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'totalUsers' => $this['totalUsers'],
            'activeUsers' => $this['activeUsers'],
            'inactiveUsers' => $this['inactiveUsers'],
            'onlineUsers' => $this['onlineUsers'],
            'auditCount' => $this['auditCount'],
            'masterDataGroupCount' => $this['masterDataGroupCount'],
            'servicePackageCount' => $this['servicePackageCount'],
            'regionCount' => $this['regionCount'],
            'inventoryReferenceCount' => $this['inventoryReferenceCount'],
            'workflowReferenceCount' => $this['workflowReferenceCount'],
            'latestAuditLogs' => AuditLogResource::collection($this['latestAuditLogs']),
        ];
    }
}
