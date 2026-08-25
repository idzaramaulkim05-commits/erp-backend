<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InstallationMaterialRequestResource;
use App\Models\InstallationMaterialRequest;
use App\Services\WorkflowService;
use Illuminate\Http\Request;

class InstallationMaterialRequestController extends Controller
{
    public function __construct(private readonly WorkflowService $workflow) {}

    public function index()
    {
        return InstallationMaterialRequestResource::collection(
            InstallationMaterialRequest::query()->latest()->get()
        );
    }

    public function updateStatus(Request $request, InstallationMaterialRequest $installationMaterialRequest)
    {
        $payload = $request->validate([
            'status' => ['required', 'string', 'in:menunggu_persetujuan_gudang,diproses_gudang,siap_diserahkan,diserahkan_ke_teknisi,ditolak'],
            'approval_notes' => ['nullable', 'string'],
        ]);

        return InstallationMaterialRequestResource::make(
            $this->workflow->updateInstallationMaterialRequestStatus(
                $installationMaterialRequest,
                $payload,
                $request->user(),
            )
        );
    }
}
