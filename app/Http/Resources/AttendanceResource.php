<?php

namespace App\Http\Resources;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Attendance */
class AttendanceResource extends JsonResource
{
    /** Sertakan URL foto bertanda tangan (hanya di endpoint detail). */
    public bool $withPhotos = false;

    public function toArray(Request $request): array
    {
        $tz = $this->checkInOffice?->timezone ?? config('attendance.timezone');
        $punch = function (string $p) use ($tz) {
            if (! $this->{"{$p}_at"}) {
                return null;
            }
            $office = $p === 'check_in' ? $this->checkInOffice : $this->checkOutOffice;

            return [
                'at' => $this->{"{$p}_at"}->copy()->setTimezone($office?->timezone ?? $tz)->toIso8601String(),
                'office' => $office ? ['id' => $office->id, 'name' => $office->name] : null,
                'distance_m' => $this->{"{$p}_distance_m"},
                'accuracy_m' => $this->{"{$p}_accuracy_m"},
                'photo_url' => $this->withPhotos && $this->{"{$p}_photo_path"}
                    ? Storage::disk(config('attendance.photo_disk'))->temporaryUrl(
                        $this->{"{$p}_photo_path"}, now()->addMinutes(config('attendance.photo_url_ttl')))
                    : null,
            ];
        };
        $s = $this->schedule_snapshot;

        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'status' => $this->status,
            'status_label' => $this->status->getLabel(),
            'late_minutes' => $this->late_minutes,
            'worked_minutes' => $this->worked_minutes,
            'is_early_leave' => $this->is_early_leave,
            'early_leave_minutes' => $this->early_leave_minutes,
            'late_code' => $this->late_code,
            'early_leave_code' => $this->early_leave_code,
            'deduction_amount' => $this->deduction_amount,
            'check_in' => $punch('check_in'),
            'check_out' => $punch('check_out'),
            'note' => $this->note,
            'schedule' => $s ? [
                'pattern_name' => $s['pattern_name'] ?? null,
                'type' => $s['type'] ?? null,
                'is_day_off' => $s['is_day_off'] ?? false,
                'start_time' => $s['start_time'] ?? null,
                'end_time' => $s['end_time'] ?? null,
                'reason' => $s['reason'] ?? null,
            ] : null,
        ];
    }
}
