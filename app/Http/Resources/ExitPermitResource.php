<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ExitPermit */
class ExitPermitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tz = config('attendance.timezone');

        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'out_at' => $this->out_at->copy()->setTimezone($tz)->toIso8601String(),
            'back_at' => $this->back_at?->copy()->setTimezone($tz)->toIso8601String(),
            'is_active' => $this->isActive(),
            'status' => $this->status,
            'review_note' => $this->review_note,
        ];
    }
}
