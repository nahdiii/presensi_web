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

    // Cek kehadiran acak (fase 2) untuk pegawai bertipe presensi tetap.
    'presence_checks' => [
        'enabled' => (bool) env('ATTENDANCE_PRESENCE_CHECKS', true),
        'min_per_day' => 1,
        'max_per_day' => 3,
        'response_minutes' => 15,
        // Tidak dikirim di menit-menit awal/akhir shift dan sekitar jam istirahat.
        'margin_minutes' => 60,
        'min_gap_minutes' => 45,
    ],

    // Pengingat absen lewat notifikasi (menit sebelum jam masuk / sesudah jam pulang).
    'reminders' => [
        'check_in_before_min' => 15,
        'check_out_after_min' => 0,
    ],

    // Disk backup database harian. Di produksi arahkan ke bucket R2 terpisah.
    'backup_disk' => env('BACKUP_DISK', 'local'),

    // Batas umur koreksi lupa absen (hari ke belakang).
    'correction_max_days' => 30,
];
