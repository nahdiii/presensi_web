<?php

use Illuminate\Support\Facades\Schedule;

// Tutup hari presensi setiap malam: tandai alpa dan tidak absen pulang.
Schedule::command('attendance:close-day')
    ->dailyAt('23:50')
    ->timezone(config('attendance.timezone'))
    ->withoutOverlapping();
