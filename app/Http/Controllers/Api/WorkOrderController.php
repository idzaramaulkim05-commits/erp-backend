<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignWorkOrderRequest;
use App\Http\Requests\SubmitFieldReportRequest;
use App\Http\Resources\WorkOrderResource;
use App\Models\WorkOrder;
use App\Services\WorkflowService;
class WorkOrderController extends Controller
{
    public function __construct(private readonly WorkflowService $workflow) {}

    public function index()
    {
        return WorkOrderResource::collection(WorkOrder::query()->latest()->get());
    }

    public function assignTech(AssignWorkOrderRequest $request, WorkOrder $workOrder)
    {
        return WorkOrderResource::make($this->workflow->assignWorkOrder($workOrder, $request->string('tech_id')->toString(), $request->user()));
    }

    public function submitReport(SubmitFieldReportRequest $request, WorkOrder $workOrder)
    {
        return WorkOrderResource::make($this->workflow->submitFieldReport($workOrder, $request->validated(), $request->user()));
    }
}
