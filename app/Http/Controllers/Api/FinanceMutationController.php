<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinanceMutationRequest;
use App\Http\Requests\UpdateFinanceMutationRequest;
use App\Http\Resources\FinanceMutationResource;
use App\Models\FinanceMutation;
use App\Services\WorkflowService;

class FinanceMutationController extends Controller
{
    public function __construct(private readonly WorkflowService $workflow) {}

    public function index()
    {
        return FinanceMutationResource::collection(
            FinanceMutation::query()->with('creator')->latest('transaction_date')->latest('created_at')->get()
        );
    }

    public function store(StoreFinanceMutationRequest $request)
    {
        $mutation = FinanceMutation::query()->create([
            'id' => 'FM-'.str_pad((string) (FinanceMutation::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            ...$request->validated(),
            'created_by_id' => $request->user()->id,
            'status' => $request->validated()['status'] ?? 'posted',
        ]);

        $this->workflow->log($request->user(), 'Finance Mutation Created', $mutation->id, $mutation->description, 'info');

        return FinanceMutationResource::make($mutation->load('creator'));
    }

    public function update(UpdateFinanceMutationRequest $request, FinanceMutation $financeMutation)
    {
        $financeMutation->update([
            ...$request->validated(),
            'status' => $request->validated()['status'] ?? $financeMutation->status,
        ]);

        $this->workflow->log($request->user(), 'Finance Mutation Updated', $financeMutation->id, $financeMutation->description, 'info');

        return FinanceMutationResource::make($financeMutation->load('creator'));
    }

    public function destroy(FinanceMutation $financeMutation)
    {
        $actor = request()->user();
        $targetId = $financeMutation->id;
        $details = $financeMutation->description;
        $financeMutation->delete();
        $this->workflow->log($actor, 'Finance Mutation Deleted', $targetId, $details, 'warning');

        return response()->noContent();
    }
}
