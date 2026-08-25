<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ReimbursementRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $receiptUrl = null;
        if ($this->receipt_path) {
            $receiptUrl = str_starts_with($this->receipt_path, 'http')
                ? $this->receipt_path
                : Storage::url($this->receipt_path);
        }

        return [
            'id' => $this->id,
            'requestedById' => $this->requested_by_id,
            'requestedByName' => optional($this->requester)->name,
            'requesterRole' => $this->requester_role,
            'requesterDivision' => $this->requester_division,
            'transactionDate' => optional($this->transaction_date)->format('Y-m-d'),
            'description' => $this->description,
            'totalClaim' => (int) $this->total_claim,
            'status' => $this->status,
            'receiptPath' => $this->receipt_path,
            'receiptUrl' => $receiptUrl,
            'financeNotes' => $this->finance_notes,
            'managementNotes' => $this->management_notes,
            'financeReviewedBy' => $this->finance_reviewed_by,
            'financeReviewedAt' => optional($this->finance_reviewed_at)->format('Y-m-d H:i:s'),
            'managementReviewedBy' => $this->management_reviewed_by,
            'managementReviewedAt' => optional($this->management_reviewed_at)->format('Y-m-d H:i:s'),
            'paidBy' => $this->paid_by,
            'submittedAt' => optional($this->submitted_at)->format('Y-m-d H:i:s'),
            'approvedAt' => optional($this->approved_at)->format('Y-m-d H:i:s'),
            'paidAt' => optional($this->paid_at)->format('Y-m-d H:i:s'),
            'createdAt' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updatedAt' => optional($this->updated_at)->format('Y-m-d H:i:s'),
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'itemName' => $item->item_name,
                'quantity' => (int) $item->quantity,
                'unit' => $item->unit,
                'unitAmount' => (int) $item->unit_amount,
                'subtotal' => (int) $item->subtotal,
                'notes' => $item->notes,
            ])->values()->all(),
        ];
    }
}
