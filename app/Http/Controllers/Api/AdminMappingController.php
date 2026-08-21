<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminMappingResource;
use App\Models\AdminMasterDataGroup;
use App\Models\NetworkOdp;

class AdminMappingController extends Controller
{
    public function index()
    {
        $odps = NetworkOdp::query()->with('ports')->orderBy('id')->get();
        $roleDivisionMap = AdminMasterDataGroup::query()->find('role_division_map');

        return AdminMappingResource::make([
            'networkSummary' => [
                'totalOdps' => $odps->count(),
                'totalPorts' => $odps->sum('total_ports'),
                'usedPorts' => $odps->sum('used_ports'),
                'availablePorts' => $odps->sum('total_ports') - $odps->sum('used_ports'),
            ],
            'odps' => $odps,
            'roleDivisionMap' => $roleDivisionMap?->items ?? [],
        ]);
    }
}
