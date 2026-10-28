<?php

namespace App\Http\Middleware;

use App\Enums\RejectReason;
use App\Exceptions\AttendanceRejected;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setiap request dari aplikasi membawa header X-Device-UID yang harus sama
 * dengan perangkat aktif pegawai. Perangkat lain ditolak 403.
 */
class EnsureKnownDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $employee = $user?->employee;
        if (! $user?->is_active) {
            throw new AttendanceRejected(RejectReason::InactiveAccount, 'Akun Anda dinonaktifkan. Hubungi Bagian SDM.');
        }
        $device = $employee?->activeDevice;
        $uid = (string) $request->header('X-Device-UID');
        if (! $device || $uid === '' || ! hash_equals($device->device_uid, $uid)) {
            throw new AttendanceRejected(RejectReason::UnknownDevice,
                'Perangkat ini tidak terdaftar untuk akun Anda. Minta Bagian SDM melakukan Reset Perangkat.');
        }

        return $next($request);
    }
}
