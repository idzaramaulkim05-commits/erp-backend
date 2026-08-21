<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcurementApprovalRequest;
use App\Http\Requests\StoreProcurementRequest;
use App\Http\Resources\ProcurementRequestResource;
use App\Models\ProcurementRequest;
use App\Services\WorkflowService;
class ProcurementController extends Controller
{
    public function __construct(private readonly WorkflowService $workflow) {}

    public function index()
    {
        return ProcurementRequestResource::collection(ProcurementRequest::query()->latest()->get());
    }

    public function store(StoreProcurementRequest $request)
    {
        return ProcurementRequestResource::make($this->workflow->createProcurement($request->validated(), $request->user()));
    }

    public function financeApprove(ProcurementApprovalRequest $request, ProcurementRequest $procurement)
    {
        return ProcurementRequestResource::make($this->workflow->financeApprove($procurement, $request->user(), $request->string('notes')->toString() ?: null));
    }

    public function managementApprove(ProcurementApprovalRequest $request, ProcurementRequest $procurement)
    {
        return ProcurementRequestResource::make($this->workflow->managementApprove($procurement, $request->user(), $request->string('notes')->toString() ?: null));
    }

    public function receive(Request $request, ProcurementRequest $procurement)
    {
        return ProcurementRequestResource::make($this->workflow->receiveProcurement($procurement, $request->user()));
    }
}
