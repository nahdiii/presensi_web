<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Bukti yang dikirim HP saat absen. Sengaja tidak ada jam di sini: waktu
 * absen selalu jam server.
 */
final readonly class PunchData
{
    public function __construct(
        public float $lat,
        public float $lng,
        public float $accuracy,
        public bool $isMocked,
        public bool $isRooted,
        public int $faceCount,
        public ?UploadedFile $photo,
        public ?string $deviceUid,
        public ?string $ip = null,
    ) {}
}
