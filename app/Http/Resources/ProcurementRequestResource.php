<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcurementRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'itemCode' => $this->item_code,
            'itemName' => $this->item_name,
            'quantity' => (int) $this->quantity,
            'unit' => $this->unit,
            'unitPrice' => (int) $this->unit_price,
            'totalAmount' => (int) $this->total_amount,
            'reason' => $this->reason,
            'requestedBy' => $this->requested_by,
            'requestedAt' => optional($this->requested_at)->format('Y-m-d H:i:s'),
            'status' => $this->status,
            'financeApproval' => $this->finance_approval,
            'managementApproval' => $this->management_approval,
            'paymentConfirmedAt' => optional($this->payment_confirmed_at)->format('Y-m-d H:i:s'),
            'paymentConfirmedBy' => $this->payment_confirmed_by,
            'paymentProofUrl' => $this->payment_proof_url ? asset($this->payment_proof_url) : null,
            'paymentChannel' => $this->payment_channel,
            'paymentNotes' => $this->payment_notes,
            'paymentDetails' => $this->payment_details,
            'orderedBy' => $this->ordered_by,
            'orderedAt' => optional($this->ordered_at)->format('Y-m-d H:i:s'),
            'orderedNotes' => $this->ordered_notes,
            'rejectionNotes' => $this->rejection_notes,
            'lastRejectedBy' => $this->last_rejected_by,
            'lastRejectedAt' => optional($this->last_rejected_at)->format('Y-m-d H:i:s'),
            'receivedAt' => optional($this->received_at)->format('Y-m-d H:i:s'),
        ];
    }
}
