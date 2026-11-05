<?php

namespace App\Http\Resources;

use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeaveRequest */
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
            'days' => $this->days,
            'reason' => $this->reason,
            'has_attachment' => $this->attachment_path !== null,
            // Izin bersama: siapa yang mengisi (bila bukan diri sendiri) dan rekan lain di dalamnya.
            'submitted_by_name' => $this->isSubmittedByOther() ? $this->submitter?->name : null,
            'group_members' => $this->group_id
                ? $this->groupMembers->where('id', '!=', $this->id)->map(fn ($l) => $l->employee->name)->values()
                : [],
            'status' => $this->status,
            'approved_by_name' => $this->approver?->name,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'review_note' => $this->review_note,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
