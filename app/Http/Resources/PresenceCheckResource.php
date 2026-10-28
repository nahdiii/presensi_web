<?php

namespace App\Http\Resources;

use App\Models\PresenceCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PresenceCheck */
class PresenceCheckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tz = config('attendance.timezone');

        return [
            'id' => $this->id,
            'result' => $this->result,
            'result_label' => PresenceCheck::LABELS[$this->result] ?? $this->result,
            'sent_at' => $this->sent_at?->copy()->setTimezone($tz)->toIso8601String(),
            'deadline_at' => $this->deadline_at?->copy()->setTimezone($tz)->toIso8601String(),
            'seconds_left' => $this->deadline_at ? max(0, (int) now()->diffInSeconds($this->deadline_at, false)) : null,
            'responded_at' => $this->responded_at?->copy()->setTimezone($tz)->toIso8601String(),
        ];
    }
}
