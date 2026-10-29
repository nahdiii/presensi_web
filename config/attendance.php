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

    // Presensi per sesi mengajar (fase 3): absen dibuka sebelum jam mulai
    // kuliah sampai jam selesai; terlambat bila lewat toleransi dari jam mulai.
    'sessions' => [
        'open_before_min' => 15,
        'late_after_min' => 15,
        'reminder_before_min' => 15,
    ],

    // Disk backup database harian. Di produksi arahkan ke bucket R2 terpisah.
    'backup_disk' => env('BACKUP_DISK', 'local'),

    // Pengajuan izin/sakit/cuti/tugas luar. Jatah cuti dihitung dalam hari
    // kerja per tahun kalender; bisa diganti per pegawai di panel admin.
    'leave' => [
        'annual_quota_days' => (int) env('ATTENDANCE_ANNUAL_LEAVE_DAYS', 12),
        'max_backdate_days' => 7,
    ],

    // Batas umur koreksi lupa absen (hari ke belakang).
    'correction_max_days' => 30,
];
