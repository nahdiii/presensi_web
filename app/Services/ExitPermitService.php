<?php

namespace App\Services;

use App\Enums\LeaveStatus;
use App\Enums\RejectReason;
use App\Exceptions\AttendanceRejected;
use App\Models\Employee;
use App\Models\ExitPermit;
use App\Models\User;
use App\Notifications\Push\PushNotifier;
use App\Support\PunchData;
use Carbon\CarbonImmutable;

/**
 * Izin keluar kampus sementara. Keluar yang sah tercatat (dan cek acak tidak
 * dikirim selama izin aktif), sehingga keluar tanpa izin jelas pelanggaran.
 */
class ExitPermitService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly PushNotifier $push,
    ) {}

    public function active(Employee $employee): ?ExitPermit
    {
        return $employee->exitPermits()->whereNull('back_at')->latest('out_at')->first();
    }

    public function start(Employee $employee, string $reason, ?string $deviceUid): ExitPermit
    {
        $this->attendance->assertDevice($employee, $deviceUid);
        $now = CarbonImmutable::now();
        $open = $this->attendance->openAttendance($employee, $now->setTimezone(config('attendance.timezone')));
        if (! $open) {
            throw new AttendanceRejected(RejectReason::NotCheckedIn, 'Izin keluar hanya bisa diajukan setelah absen masuk.');
        }
        if ($active = $this->active($employee)) {
            throw new AttendanceRejected(RejectReason::ExitPermitActive,
                'Anda masih tercatat keluar sejak '.$active->out_at->setTimezone(config('attendance.timezone'))->format('H:i').'.');
        }

        return $employee->exitPermits()->create([
            'institution_id' => $employee->institution_id,
            'attendance_id' => $open->id,
            'reason' => $reason,
            'out_at' => $now,
            'status' => LeaveStatus::Pending,
        ]);
    }

    /** "Kembali": lokasi harus di radius kampus lagi. */
    public function returnBack(Employee $employee, PunchData $p): ExitPermit
    {
        $ctx = [];

        return $this->attendance->audited($employee, 'return', $p, $ctx, function () use ($employee, $p, &$ctx) {
            $this->attendance->assertDevice($employee, $p->deviceUid);
            $permit = $this->active($employee)
                ?? throw new AttendanceRejected(RejectReason::NoExitPermit, 'Tidak ada izin keluar yang sedang aktif.');
            [, $distance] = $this->attendance->assertLocation($employee, $p, $ctx);
            $permit->update(['back_at' => now(), 'back_distance_m' => round($distance, 2)]);

            return $permit;
        });
    }

    /** Absen pulang otomatis menutup izin keluar yang masih terbuka. */
    public function closeActive(Employee $employee, CarbonImmutable $at): void
    {
        $this->active($employee)?->update(['back_at' => $at->utc()]);
    }

    public function decide(ExitPermit $permit, User $approver, bool $approve, ?string $note = null): void
    {
        $permit->update([
            'status' => $approve ? LeaveStatus::Approved : LeaveStatus::Rejected,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'review_note' => $note,
        ]);
        $this->push->send($permit->employee, 'Izin keluar '.($approve ? 'disetujui' : 'ditolak'),
            $note ?: 'Izin keluar pukul '.$permit->out_at->setTimezone(config('attendance.timezone'))->format('H:i').' sudah diputuskan.',
            ['type' => 'exit_permit', 'id' => (string) $permit->id]);
    }
}
