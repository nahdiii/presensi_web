<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/** Mengecilkan foto selfie supaya penyimpanan hemat. */
class ProcessAttendancePhoto implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $disk, public string $path) {}

    public function handle(): void
    {
        $storage = Storage::disk($this->disk);
        $raw = $storage->get($this->path);
        if ($raw === null || ! function_exists('imagecreatefromstring')) {
            return;
        }
        $img = @imagecreatefromstring($raw);
        if (! $img) {
            return;
        }
        $maxWidth = config('attendance.photo_max_width');
        if (imagesx($img) > $maxWidth) {
            $img = imagescale($img, $maxWidth);
        }
        ob_start();
        imagejpeg($img, null, 70);
        $storage->put($this->path, ob_get_clean());
        imagedestroy($img);
    }
}
