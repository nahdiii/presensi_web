<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CorrectionController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PresenceController;
use App\Http\Middleware\EnsureKnownDevice;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('server-time', [AttendanceController::class, 'serverTime']);

    Route::middleware(['auth:sanctum', EnsureKnownDevice::class])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [MeController::class, 'show']);
        Route::post('me/password', [MeController::class, 'password']);
        Route::post('devices/fcm-token', [MeController::class, 'fcmToken']);

        Route::get('attendance/today', [AttendanceController::class, 'today']);
        Route::get('attendance/history', [AttendanceController::class, 'history']);
        Route::get('attendance/{attendance}', [AttendanceController::class, 'show'])->whereNumber('attendance');
        Route::get('leaves', [LeaveController::class, 'index']);
        Route::post('leaves', [LeaveController::class, 'store']);

        // Fase 2
        Route::get('presence-checks/pending', [PresenceController::class, 'pending']);
        Route::get('exit-permits', [PresenceController::class, 'permits']);
        Route::post('exit-permits', [PresenceController::class, 'startPermit']);
        Route::get('corrections', [CorrectionController::class, 'index']);
        Route::post('corrections', [CorrectionController::class, 'store']);
    });

    // Absen tidak memakai EnsureKnownDevice: perangkat dicek di
    // AttendanceService supaya percobaan dari HP lain ikut tercatat di audit.
    Route::middleware(['auth:sanctum', 'throttle:attendance'])->group(function () {
        Route::post('attendance/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('attendance/check-out', [AttendanceController::class, 'checkOut']);
        Route::post('presence-checks/{presenceCheck}/respond', [PresenceController::class, 'respond'])->whereNumber('presenceCheck');
        Route::post('exit-permits/{exitPermit}/return', [PresenceController::class, 'returnPermit'])->whereNumber('exitPermit');
    });
});
