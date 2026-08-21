<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'fromDivision' => $this->from_division,
            'toDivision' => $this->to_division,
            'priority' => $this->priority,
            'status' => $this->status,
            'relatedCustomerId' => $this->related_customer_id,
            'relatedTicketId' => $this->related_ticket_id,
            'createdAt' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'dueDate' => optional($this->due_date)->format('Y-m-d H:i:s'),
            'createdBy' => $this->created_by,
            'assignedTo' => $this->assigned_to,
            'resolutionNotes' => $this->resolution_notes,
        ];
    }
}
