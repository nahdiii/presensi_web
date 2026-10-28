<?php

return [

    // Zona waktu institusi, dipakai bila kantor tidak punya zona sendiri.
    'timezone' => env('ATTENDANCE_TIMEZONE', 'Asia/Makassar'),

    // Akurasi GPS terburuk (meter) yang masih diterima.
    'max_accuracy_m' => (int) env('ATTENDANCE_MAX_ACCURACY_M', 50),

    // Disk foto selfie. Di produksi isi "s3" (Cloudflare R2/S3) karena disk
    // container hilang setiap redeploy.
    'photo_disk' => env('ATTENDANCE_PHOTO_DISK', 'attendance'),

    // Batas ukuran unggahan foto (KB) dan lebar maksimum setelah dikompres.
    'photo_max_kb' => 2048,
    'photo_max_width' => 720,

    // Berapa lama URL foto bertanda tangan berlaku (menit).
    'photo_url_ttl' => 10,

    // Batas waktu absen pulang setelah jam selesai shift (menit). Lewat dari
    // ini, hari itu dianggap tidak absen pulang (incomplete).
    'checkout_grace_min' => 240,
];
