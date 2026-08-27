<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcurementApprovalRequest;
use App\Http\Requests\ProcurementRejectionRequest;
use App\Http\Requests\StoreProcurementRequest;
use App\Http\Requests\UpdateProcurementRequest;
use App\Http\Resources\ProcurementRequestResource;
use App\Models\ProcurementRequest;
use App\Services\WorkflowService;
use Illuminate\Http\Request;

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

    public function update(UpdateProcurementRequest $request, ProcurementRequest $procurement)
    {
        return ProcurementRequestResource::make($this->workflow->updateProcurement($procurement, $request->validated(), $request->user()));
    }

    public function financeApprove(ProcurementApprovalRequest $request, ProcurementRequest $procurement)
    {
        return ProcurementRequestResource::make($this->workflow->financeApprove($procurement, $request->user(), $request->string('notes')->toString() ?: null));
    }

    public function financeReject(ProcurementRejectionRequest $request, ProcurementRequest $procurement)
    {
        return ProcurementRequestResource::make($this->workflow->financeReject($procurement, $request->user(), $request->string('notes')->toString()));
    }

    public function managementApprove(ProcurementApprovalRequest $request, ProcurementRequest $procurement)
    {
        return ProcurementRequestResource::make($this->workflow->managementApprove($procurement, $request->user(), $request->string('notes')->toString() ?: null));
    }

    public function managementReject(ProcurementRejectionRequest $request, ProcurementRequest $procurement)
    {
        return ProcurementRequestResource::make($this->workflow->managementReject($procurement, $request->user(), $request->string('notes')->toString()));
    }

    public function markOrdered(ProcurementApprovalRequest $request, ProcurementRequest $procurement)
    {
        return ProcurementRequestResource::make($this->workflow->markProcurementOrdered($procurement, $request->user(), $request->string('notes')->toString() ?: null));
    }

    public function confirmPayment(Request $request, ProcurementRequest $procurement)
    {
        $payload = $request->validate([
            'payment_channel' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'payment_notes' => ['nullable', 'string'],
            'payment_proof' => ['nullable'],
        ]);

        return ProcurementRequestResource::make(
            $this->workflow->confirmProcurementPayment(
                $procurement,
                $request->user(),
                $payload,
                $request->file('payment_proof') ?? $request->file('proof')
            )
        );
    }

    public function receive(Request $request, ProcurementRequest $procurement)
    {
        return ProcurementRequestResource::make($this->workflow->receiveProcurement($procurement, $request->user()));
    }
}
