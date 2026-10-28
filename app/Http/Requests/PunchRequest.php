<?php

namespace App\Http\Requests;

use App\Support\PunchData;
use Illuminate\Foundation\Http\FormRequest;

/** Validasi input mentah absen masuk/pulang. Aturan bisnis ada di AttendanceService. */
class PunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->employee !== null;
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0'],
            'is_mocked' => ['required', 'boolean'],
            'is_rooted' => ['sometimes', 'boolean'],
            'face_count' => ['required', 'integer', 'min:0', 'max:20'],
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,heic', 'max:'.config('attendance.photo_max_kb')],
        ];
    }

    public function attributes(): array
    {
        return ['photo' => 'foto selfie', 'accuracy' => 'akurasi GPS'];
    }

    public function toPunch(): PunchData
    {
        return new PunchData(
            lat: (float) $this->input('latitude'),
            lng: (float) $this->input('longitude'),
            accuracy: (float) $this->input('accuracy'),
            isMocked: $this->boolean('is_mocked'),
            isRooted: $this->boolean('is_rooted'),
            faceCount: (int) $this->input('face_count'),
            photo: $this->file('photo'),
            deviceUid: $this->header('X-Device-UID'),
            ip: $this->ip(),
        );
    }
}
