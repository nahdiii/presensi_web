<?php

namespace App\Http\Requests;

use App\Support\PunchData;
use Illuminate\Foundation\Http\FormRequest;

/** Bukti lokasi tanpa selfie, mis. untuk "Kembali" dari izin keluar. */
class LocationRequest extends FormRequest
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
        ];
    }

    public function toPunch(): PunchData
    {
        return new PunchData(
            lat: (float) $this->input('latitude'),
            lng: (float) $this->input('longitude'),
            accuracy: (float) $this->input('accuracy'),
            isMocked: $this->boolean('is_mocked'),
            isRooted: $this->boolean('is_rooted'),
            faceCount: 0,
            photo: null,
            deviceUid: $this->header('X-Device-UID'),
            ip: $this->ip(),
        );
    }
}
