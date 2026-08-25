<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcurementApprovalRequest;
use App\Http\Requests\ProcurementRejectionRequest;
use App\Http\Requests\StoreReimbursementDraftRequest;
use App\Http\Requests\UpdateReimbursementDraftRequest;
use App\Http\Resources\ReimbursementRequestResource;
use App\Models\ReimbursementRequest;
use App\Services\WorkflowService;

class ReimbursementController extends Controller
{
    public function __construct(private readonly WorkflowService $workflow) {}

    public function index()
    {
        $user = request()->user();

        $query = ReimbursementRequest::query()
            ->with(['items', 'requester'])
            ->latest('created_at');

        if (! $user->hasAnyRole(['superadmin', 'finance', 'management'])) {
            $query->where('requested_by_id', $user->id);
        }

        return ReimbursementRequestResource::collection($query->get());
    }

    public function show(ReimbursementRequest $reimbursement)
    {
        $user = request()->user();
        abort_unless(
            $user->hasAnyRole(['superadmin', 'finance', 'management']) || $reimbursement->requested_by_id === $user->id,
            403
        );

        return ReimbursementRequestResource::make($reimbursement->load(['items', 'requester']));
    }

    public function store(StoreReimbursementDraftRequest $request)
    {
        return ReimbursementRequestResource::make(
            $this->workflow->createReimbursementDraft($request->validated(), $request->user(), $request->file('receipt'))
                ->load(['items', 'requester'])
        );
    }

    public function update(UpdateReimbursementDraftRequest $request, ReimbursementRequest $reimbursement)
    {
        return ReimbursementRequestResource::make(
            $this->workflow->updateReimbursementDraft($reimbursement, $request->validated(), $request->user(), $request->file('receipt'))
                ->load(['items', 'requester'])
        );
    }

    public function submit(ReimbursementRequest $reimbursement)
    {
        return ReimbursementRequestResource::make(
            $this->workflow->submitReimbursementRequest($reimbursement, request()->user())
                ->load(['items', 'requester'])
        );
    }

    public function financeApprove(ProcurementApprovalRequest $request, ReimbursementRequest $reimbursement)
    {
        return ReimbursementRequestResource::make(
            $this->workflow->financeApproveReimbursement($reimbursement, $request->user(), $request->string('notes')->toString() ?: null)
                ->load(['items', 'requester'])
        );
    }

    public function financeReject(ProcurementRejectionRequest $request, ReimbursementRequest $reimbursement)
    {
        return ReimbursementRequestResource::make(
            $this->workflow->financeRejectReimbursement($reimbursement, $request->user(), $request->string('notes')->toString())
                ->load(['items', 'requester'])
        );
    }

    public function forwardToManagement(ProcurementRejectionRequest $request, ReimbursementRequest $reimbursement)
    {
        return ReimbursementRequestResource::make(
            $this->workflow->forwardReimbursementToManagement($reimbursement, $request->user(), $request->string('notes')->toString())
                ->load(['items', 'requester'])
        );
    }

    public function managementApprove(ProcurementApprovalRequest $request, ReimbursementRequest $reimbursement)
    {
        return ReimbursementRequestResource::make(
            $this->workflow->managementApproveReimbursement($reimbursement, $request->user(), $request->string('notes')->toString() ?: null)
                ->load(['items', 'requester'])
        );
    }

    public function managementReject(ProcurementRejectionRequest $request, ReimbursementRequest $reimbursement)
    {
        return ReimbursementRequestResource::make(
            $this->workflow->managementRejectReimbursement($reimbursement, $request->user(), $request->string('notes')->toString())
                ->load(['items', 'requester'])
        );
    }

    public function markPaid(ProcurementApprovalRequest $request, ReimbursementRequest $reimbursement)
    {
        return ReimbursementRequestResource::make(
            $this->workflow->markReimbursementPaid($reimbursement, $request->user(), $request->string('notes')->toString() ?: null)
                ->load(['items', 'requester'])
        );
    }
}
