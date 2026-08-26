<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignWorkOrderRequest;
use App\Http\Requests\InstallationNocVerifyRequest;
use App\Http\Requests\SubmitFieldReportRequest;
use App\Http\Resources\WorkOrderResource;
use App\Models\WorkOrder;
use App\Services\WorkflowService;

class WorkOrderController extends Controller
{
    public function __construct(private readonly WorkflowService $workflow) {}

    public function index()
    {
        return WorkOrderResource::collection(WorkOrder::query()->with(['serviceRegistration', 'customer'])->latest()->get());
    }

    public function assignTech(AssignWorkOrderRequest $request, WorkOrder $workOrder)
    {
        return WorkOrderResource::make($this->workflow->assignWorkOrder($workOrder, $request->string('tech_id')->toString(), $request->user()));
    }

    public function leadAssign(AssignWorkOrderRequest $request, WorkOrder $workOrder)
    {
        return $this->assignTech($request, $workOrder);
    }

    public function submitReport(SubmitFieldReportRequest $request, WorkOrder $workOrder)
    {
        $updated = $this->workflow->submitFieldReport($workOrder, $request->validated(), $request->user());
        return WorkOrderResource::make($updated->load(['serviceRegistration', 'customer']));
    }

    public function submitInstallationReport(SubmitFieldReportRequest $request, WorkOrder $workOrder)
    {
        return $this->submitReport($request, $workOrder);
    }

    public function startInstallation(WorkOrder $workOrder)
    {
        return WorkOrderResource::make($this->workflow->startInstallationWorkOrder($workOrder, request()->user()));
    }

    public function confirmFieldAssignment(WorkOrder $workOrder)
    {
        return WorkOrderResource::make($this->workflow->confirmFieldAssignment($workOrder, request()->user()));
    }

    public function returnToTech(WorkOrder $workOrder)
    {
        $payload = request()->validate([
            'notes' => ['nullable', 'string'],
        ]);

        return WorkOrderResource::make($this->workflow->returnInstallationToTech($workOrder, $payload, request()->user()));
    }

    public function nocFinalVerify(InstallationNocVerifyRequest $request, WorkOrder $workOrder)
    {
        return WorkOrderResource::make($this->workflow->nocFinalVerifyInstallation($workOrder, $request->validated(), $request->user()));
    }

    public function requestPppoe(WorkOrder $workOrder)
    {
        $payload = request()->validate([
            'notes' => ['nullable', 'string'],
        ]);

        return WorkOrderResource::make($this->workflow->requestInstallationPppoe($workOrder, $payload, request()->user()));
    }

    public function approvePppoeRequest(WorkOrder $workOrder)
    {
        $payload = request()->validate([
            'pppoe_username' => ['required', 'string'],
            'pppoe_password' => ['required', 'string'],
            'vlan' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        return WorkOrderResource::make($this->workflow->approveInstallationPppoeRequest($workOrder, $payload, request()->user()));
    }

    public function rejectPppoeRequest(WorkOrder $workOrder)
    {
        $payload = request()->validate([
            'notes' => ['required', 'string'],
        ]);

        return WorkOrderResource::make($this->workflow->rejectInstallationPppoeRequest($workOrder, $payload, request()->user()));
    }

    public function confirmInstallationCash(WorkOrder $workOrder)
    {
        $payload = request()->validate([
            'notes' => ['nullable', 'string'],
            'payment_channel' => ['nullable', 'string'],
        ]);

        return WorkOrderResource::make($this->workflow->confirmInstallationPayment($workOrder, 'tunai', $payload, request()->user()));
    }

    public function confirmInstallationTransfer(WorkOrder $workOrder)
    {
        $payload = request()->validate([
            'notes' => ['nullable', 'string'],
            'payment_channel' => ['nullable', 'string'],
        ]);

        return WorkOrderResource::make($this->workflow->confirmInstallationPayment($workOrder, 'transfer', $payload, request()->user()));
    }
}
