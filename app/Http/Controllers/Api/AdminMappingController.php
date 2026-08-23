<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminMappingResource;
use App\Models\NetworkOdp;
use App\Models\Role;

class AdminMappingController extends Controller
{
    public function index()
    {
        $odps = NetworkOdp::query()->with('ports')->orderBy('id')->get();
        $roleDivisionMap = Role::query()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Role $role) => [
                'role' => $role->key,
                'roleTitle' => $role->label,
                'division' => $role->division,
                'description' => $role->description,
                'isActive' => (bool) $role->is_active,
            ]);

        return AdminMappingResource::make([
            'networkSummary' => [
                'totalOdps' => $odps->count(),
                'totalPorts' => $odps->sum('total_ports'),
                'usedPorts' => $odps->sum('used_ports'),
                'availablePorts' => $odps->sum('total_ports') - $odps->sum('used_ports'),
            ],
            'odps' => $odps,
            'roleDivisionMap' => $roleDivisionMap,
        ]);
    }
}
