<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** Profil pegawai yang sedang login. @mixin \App\Models\Employee */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $device = $this->activeDevice;

        return [
            'id' => $this->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'nip' => $this->nip,
            'nidn' => $this->nidn,
            'phone' => $this->phone,
            'employee_type' => $this->employee_type,
            'attendance_type' => $this->attendance_type,
            'roles' => $this->user->getRoleNames(),
            'unit' => $this->unit ? ['id' => $this->unit->id, 'name' => $this->unit->name] : null,
            'offices' => OfficeResource::collection($this->offices->where('is_active', true)->values()),
            'device' => $device ? [
                'model' => $device->model,
                'platform' => $device->platform,
                'bound_at' => $device->bound_at?->toIso8601String(),
            ] : null,
            'photo_url' => $this->photo_path
                ? Storage::disk(config('attendance.photo_disk'))->temporaryUrl($this->photo_path, now()->addMinutes(60))
                : null,
        ];
    }
}
