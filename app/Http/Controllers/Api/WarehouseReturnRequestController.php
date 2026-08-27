<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WarehouseReturnRequestResource;
use App\Models\WarehouseReturnRequest;
use App\Services\WorkflowService;
use Illuminate\Http\Request;

class WarehouseReturnRequestController extends Controller
{
    public function __construct(private readonly WorkflowService $workflow) {}

    public function index()
    {
        return WarehouseReturnRequestResource::collection(
            WarehouseReturnRequest::query()->latest()->get()
        );
    }

    public function qc(Request $request, WarehouseReturnRequest $warehouseReturnRequest)
    {
        $payload = $request->validate([
            'notes' => ['nullable', 'string'],
            'condition' => ['nullable', 'string'],
            'device_condition' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
        ]);

        return WarehouseReturnRequestResource::make(
            $this->workflow->completeWarehouseReturnQc(
                $warehouseReturnRequest,
                $payload,
                $request->user(),
            )
        );
    }
}
