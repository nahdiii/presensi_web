<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AttendanceCorrection */
class CorrectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'check_in_time' => $this->check_in_time ? substr($this->check_in_time, 0, 5) : null,
            'check_out_time' => $this->check_out_time ? substr($this->check_out_time, 0, 5) : null,
            'reason' => $this->reason,
            'status' => $this->status,
            'approved_by_name' => $this->approver?->name,
            'review_note' => $this->review_note,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
