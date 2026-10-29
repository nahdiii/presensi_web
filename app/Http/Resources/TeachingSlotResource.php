<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu jadwal mengajar hari ini untuk beranda: jadwal, keadaan
 * (upcoming / open / done / missed / off), dan presensinya bila ada.
 * Dibuat dari item TeachingSessionService::forDay().
 */
class TeachingSlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $s = $this->resource['schedule'];

        return [
            'schedule_id' => $s->id,
            'course_code' => $s->course->code,
            'course_name' => $s->course->name,
            'credits' => $s->course->credits,
            'class_name' => $s->class_name,
            'room' => $s->room,
            'office' => $s->office ? ['id' => $s->office->id, 'name' => $s->office->name] : null,
            'start_time' => substr($s->start_time, 0, 5),
            'end_time' => substr($s->end_time, 0, 5),
            'opens_at' => $this->resource['opens_at'],
            'state' => $this->resource['state'],
            'message' => $this->resource['message'],
            'session' => $this->resource['session'] ? new TeachingSessionResource($this->resource['session']) : null,
        ];
    }
}
