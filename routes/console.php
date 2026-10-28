<?php

use Illuminate\Support\Facades\Schedule;

$tz = config('attendance.timezone');

// Tutup hari presensi setiap malam: tandai alpa dan tidak absen pulang.
Schedule::command('attendance:close-day')->dailyAt('23:50')->timezone($tz)->withoutOverlapping();

// Cek kehadiran acak dan pengingat absen (fase 2).
Schedule::command('presence-checks:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('attendance:remind')->everyFiveMinutes()->withoutOverlapping();

// Backup harian; restore dengan pg_restore --clean -d <db> <berkas.dump>.
Schedule::command('backup:database')->dailyAt('02:00')->timezone($tz)->withoutOverlapping();
