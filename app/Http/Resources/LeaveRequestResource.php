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
            'type_label' => $this->typeLabel(),
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'days' => $this->days,
            // Hari yang dibatalkan karena pegawai masuk kerja (Y-m-d).
            'cancelled_dates' => $this->cancelled_dates ?? [],
            'reason' => $this->reason,
            'has_attachment' => $this->attachment_path !== null,
            'attachment_url' => $this->attachmentUrl(),
            'attachment_is_image' => $this->attachment_path !== null && $this->attachmentIsImage(),
            // Izin bersama: siapa yang mengisi (bila bukan diri sendiri) dan rekan lain di dalamnya.
            'submitted_by_name' => $this->isSubmittedByOther() ? $this->submitter?->name : null,
            'group_members' => $this->group_id
                ? $this->groupMembers->where('id', '!=', $this->id)->map(fn ($l) => $l->employee->name)->values()
                : [],
            // Rincian rekan dalam izin bersama: masing-masing diputuskan kepala unitnya sendiri.
            'group' => $this->group_id
                ? $this->groupMembers->where('id', '!=', $this->id)->map(fn ($l) => [
                    'name' => $l->employee->name,
                    'unit_name' => $l->employee->unit?->name,
                    'status' => $l->status,
                    'status_label' => $l->status->getLabel(),
                ])->values()
                : [],
            'deduction' => $this->deductionSummary(),
            'status' => $this->status,
            'approved_by_name' => $this->approver?->name,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'review_note' => $this->review_note,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
