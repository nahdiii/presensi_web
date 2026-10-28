<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LeaveRequest */
class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $this->type->getLabel(),
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'reason' => $this->reason,
            'has_attachment' => $this->attachment_path !== null,
            'status' => $this->status,
            'approved_by_name' => $this->approver?->name,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'review_note' => $this->review_note,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
