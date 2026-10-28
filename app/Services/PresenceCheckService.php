<?php

namespace App\Services;

use App\Enums\AttendanceType;
use App\Enums\RejectReason;
use App\Exceptions\AttendanceRejected;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\PresenceCheck;
use App\Notifications\Push\PushNotifier;
use App\Support\DaySchedule;
use App\Support\PunchData;
use Carbon\CarbonImmutable;

/**
 * Cek kehadiran acak untuk pegawai presensi tetap: 1–3 kali per hari pada
 * waktu acak di jam kerja, di luar jam istirahat dan izin keluar. Pegawai
 * menekan "Saya di kampus" dalam 15 menit dengan cek lokasi dan selfie yang
 * sama seperti absen.
 */
class PresenceCheckService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly PushNotifier $push,
    ) {}

    /** Dijadwalkan saat absen masuk. Waktunya tidak diketahui pegawai sampai notifikasi tiba. */
    public function planFor(Attendance $attendance, Employee $employee): void
    {
        $cfg = config('attendance.presence_checks');
        if (! $cfg['enabled'] || $employee->attendance_type !== AttendanceType::Fixed || ! $attendance->schedule_snapshot) {
            return;
        }
        $schedule = DaySchedule::fromArray($attendance->schedule_snapshot);
        if ($schedule->isDayOff || $schedule->isFlexible()) {
            return;
        }

        $date = $attendance->date->toDateString();
        $tz = $attendance->checkInOffice?->timezone ?? config('attendance.timezone');
        $now = CarbonImmutable::now($tz);
        $from = $schedule->startAt($date, $tz)->addMinutes($cfg['margin_minutes'])->max($now->addMinutes(30));
        $to = $schedule->endAt($date, $tz)->subMinutes($cfg['margin_minutes']);

        $slots = [];
        for ($t = $from; $t->lt($to); $t = $t->addMinutes(5)) {
            if (! $this->duringBreak($schedule, $date, $tz, $t)) {
                $slots[] = $t;
            }
        }
        if (! $slots) {
            return;
        }

        $wanted = random_int($cfg['min_per_day'], $cfg['max_per_day']);
        $picked = [];
        foreach (collect($slots)->shuffle()->all() as $slot) {
            $tooClose = collect($picked)->contains(fn ($p) => abs($p->diffInMinutes($slot)) < $cfg['min_gap_minutes']);
            if (! $tooClose) {
                $picked[] = $slot;
            }
            if (count($picked) === $wanted) {
                break;
            }
        }

        foreach ($picked as $at) {
            PresenceCheck::create([
                'institution_id' => $employee->institution_id,
                'employee_id' => $employee->id,
                'attendance_id' => $attendance->id,
                'result' => PresenceCheck::SCHEDULED,
                'scheduled_at' => $at->utc(),
            ]);
        }
    }

    /**
     * Dijalankan scheduler tiap menit: kirim cek yang sudah waktunya, dan
     * tandai yang lewat batas sebagai tidak merespons.
     */
    public function dispatchDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $counts = ['sent' => 0, 'skipped' => 0, 'cancelled' => 0, 'no_response' => 0];

        $due = PresenceCheck::with(['employee.user', 'employee.activeDevice', 'attendance'])
            ->where('result', PresenceCheck::SCHEDULED)
            ->where('scheduled_at', '<=', $now->utc())
            ->get();
        foreach ($due as $check) {
            if ($check->attendance?->check_out_at || ! $check->employee->user->is_active) {
                $check->update(['result' => PresenceCheck::CANCELLED]);
                $counts['cancelled']++;
            } elseif (app(ExitPermitService::class)->active($check->employee)) {
                $check->update(['result' => PresenceCheck::SKIPPED]);
                $counts['skipped']++;
            } else {
                $check->update([
                    'result' => PresenceCheck::PENDING,
                    'sent_at' => $now->utc(),
                    'deadline_at' => $now->addMinutes(config('attendance.presence_checks.response_minutes'))->utc(),
                ]);
                $this->push->send($check->employee, 'Cek kehadiran',
                    'Tekan "Saya di kampus" dalam '.config('attendance.presence_checks.response_minutes').' menit.',
                    ['type' => 'presence_check', 'id' => (string) $check->id]);
                $counts['sent']++;
            }
        }

        $counts['no_response'] = PresenceCheck::where('result', PresenceCheck::PENDING)
            ->where('deadline_at', '<', $now->utc())
            ->update(['result' => PresenceCheck::NO_RESPONSE]);

        return $counts;
    }

    /** Cek yang sedang menunggu jawaban pegawai, bila ada. */
    public function pendingFor(Employee $employee): ?PresenceCheck
    {
        return $employee->presenceChecks()
            ->where('result', PresenceCheck::PENDING)
            ->where('deadline_at', '>=', now())
            ->latest('sent_at')
            ->first();
    }

    /**
     * Jawaban "Saya di kampus". Di luar radius tetap dicatat (out_of_radius)
     * supaya terlihat oleh pimpinan unit, lalu pegawai diberi tahu.
     */
    public function respond(Employee $employee, PresenceCheck $check, PunchData $p): PresenceCheck
    {
        $ctx = [];

        return $this->attendance->audited($employee, 'check', $p, $ctx, function () use ($employee, $check, $p, &$ctx) {
            $this->attendance->assertDevice($employee, $p->deviceUid);
            if ($check->employee_id !== $employee->id || $check->result !== PresenceCheck::PENDING || now()->gt($check->deadline_at)) {
                throw new AttendanceRejected(RejectReason::CheckExpired, 'Cek kehadiran ini sudah tidak berlaku.');
            }

            try {
                [$office, $distance] = $this->attendance->assertLocation($employee, $p, $ctx);
            } catch (AttendanceRejected $e) {
                if (in_array($e->reason, [RejectReason::OutOfRadius, RejectReason::OfficeNotAllowed], true)) {
                    $check->update([
                        'result' => PresenceCheck::OUT_OF_RADIUS, 'responded_at' => now(),
                        'office_id' => $ctx['office_id'] ?? null, 'distance_m' => $ctx['distance_m'] ?? null,
                        'lat' => $p->lat, 'lng' => $p->lng, 'accuracy_m' => $p->accuracy,
                    ]);
                }
                throw $e;
            }
            $this->attendance->assertFace($p);

            $photo = $this->attendance->storePhoto($employee, $p, CarbonImmutable::now($office->timezone)->toDateString(), 'check');
            $check->update([
                'result' => PresenceCheck::OK, 'responded_at' => now(),
                'office_id' => $office->id, 'distance_m' => round($distance, 2),
                'lat' => $p->lat, 'lng' => $p->lng, 'accuracy_m' => $p->accuracy, 'photo_path' => $photo,
            ]);
            $this->attendance->queuePhoto($photo);

            return $check;
        });
    }

    /** Saat absen pulang, cek yang belum dikirim tidak perlu lagi. */
    public function cancelRemaining(Attendance $attendance): void
    {
        PresenceCheck::where('attendance_id', $attendance->id)
            ->where('result', PresenceCheck::SCHEDULED)
            ->update(['result' => PresenceCheck::CANCELLED]);
    }

    private function duringBreak(DaySchedule $s, string $date, string $tz, CarbonImmutable $t): bool
    {
        if (! $s->breakStart || ! $s->breakEnd) {
            return false;
        }
        $margin = 15;
        $bs = CarbonImmutable::parse("$date {$s->breakStart}", $tz)->subMinutes($margin);
        $be = CarbonImmutable::parse("$date {$s->breakEnd}", $tz)->addMinutes($margin);

        return $t->betweenIncluded($bs, $be);
    }
}
