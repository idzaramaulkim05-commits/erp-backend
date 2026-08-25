<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FinanceMutationResource;
use App\Models\BillingRecord;
use App\Models\FinanceMutation;
use App\Models\ReimbursementRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class FinancialLedgerController extends Controller
{
    public function index(): JsonResponse
    {
        $billingEntries = BillingRecord::query()
            ->where('status', 'paid')
            ->with('customer')
            ->get()
            ->map(fn (BillingRecord $record) => [
                'id' => 'billing-'.$record->id,
                'transactionDate' => optional($record->paid_at ?? $record->due_date)->format('Y-m-d'),
                'source' => 'billing',
                'type' => 'inflow',
                'category' => 'Billing',
                'amount' => (int) $record->amount,
                'description' => 'Pembayaran pelanggan '.optional($record->customer)->name,
                'reference' => $record->customer_id,
                'status' => $record->status,
                'actorName' => optional($record->customer)->name,
            ]);

        $reimbursementEntries = ReimbursementRequest::query()
            ->with('requester')
            ->where('status', 'paid')
            ->get()
            ->map(fn (ReimbursementRequest $request) => [
                'id' => 'reimburse-'.$request->id,
                'transactionDate' => optional($request->paid_at ?? $request->approved_at ?? $request->transaction_date)->format('Y-m-d'),
                'source' => 'reimburse',
                'type' => 'outflow',
                'category' => 'Rembes Pegawai',
                'amount' => (int) $request->total_claim,
                'description' => $request->description,
                'reference' => $request->id,
                'status' => $request->status,
                'actorName' => optional($request->requester)->name,
            ]);

        $manualEntries = FinanceMutation::query()
            ->with('creator')
            ->get()
            ->map(fn (FinanceMutation $mutation) => [
                'id' => 'mutation-'.$mutation->id,
                'transactionDate' => optional($mutation->transaction_date)->format('Y-m-d'),
                'source' => 'manual_mutation',
                'type' => $mutation->type,
                'category' => $mutation->category,
                'amount' => (int) $mutation->amount,
                'description' => $mutation->description,
                'reference' => $mutation->reference,
                'status' => $mutation->status,
                'actorName' => optional($mutation->creator)->name,
            ]);

        $entries = (new Collection())
            ->concat($billingEntries)
            ->concat($reimbursementEntries)
            ->concat($manualEntries)
            ->sortByDesc(fn (array $entry) => ($entry['transactionDate'] ?? '').' '.$entry['id'])
            ->values();

        return response()->json(['data' => $entries]);
    }
}
