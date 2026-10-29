<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TeachingSession */
class TeachingSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tz = $this->office?->timezone ?? config('attendance.timezone');

        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'status' => $this->status,
            'status_label' => $this->status->getLabel(),
            'course_code' => $this->course_code,
            'course_name' => $this->course_name,
            'class_name' => $this->class_name,
            'room' => $this->room,
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'checked_in_at' => $this->checked_in_at?->copy()->setTimezone($tz)->toIso8601String(),
            'late_minutes' => $this->late_minutes,
            'office' => $this->office ? ['id' => $this->office->id, 'name' => $this->office->name] : null,
            'topic' => $this->topic,
        ];
    }
}
