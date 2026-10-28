<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Notifications\Push\PushNotifier;
use App\Support\DaySchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Koreksi lupa absen. Jam yang disetujui pimpinan ditulis ke presensi hari
 * itu dan statusnya dihitung ulang dengan aturan yang sama seperti absen biasa.
 */
class CorrectionService
{
    public function __construct(
        private readonly ShiftResolver $shifts,
        private readonly AttendanceService $attendance,
        private readonly PushNotifier $push,
    ) {}

    public function approve(AttendanceCorrection $c, User $approver, ?string $note = null): Attendance
    {
        $row = DB::transaction(function () use ($c, $approver, $note) {
            $employee = $c->employee;
            $date = $c->date->toDateString();
            $tz = $employee->offices->first()?->timezone ?? config('attendance.timezone');
            $row = Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->lockForUpdate()->first()
                ?? new Attendance(['employee_id' => $employee->id, 'date' => $date, 'institution_id' => $employee->institution_id]);
            $schedule = $row->schedule_snapshot
                ? DaySchedule::fromArray($row->schedule_snapshot)
                : $this->shifts->resolve($employee, $date);

            if ($c->check_in_time) {
                $in = CarbonImmutable::parse("$date {$c->check_in_time}", $tz);
                [$status, $late] = $schedule->isDayOff
                    ? [AttendanceStatus::Present, 0]
                    : $this->attendance->resolveStatus($schedule, $date, $tz, $in);
                $row->fill(['check_in_at' => $in->utc(), 'status' => $status, 'late_minutes' => $late]);
            }
            if ($c->check_out_time && $row->check_in_at) {
                $in = CarbonImmutable::instance($row->check_in_at)->setTimezone($tz);
                $out = CarbonImmutable::parse("$date {$c->check_out_time}", $tz);
                if ($out->lte($in)) {
                    $out = $out->addDay(); // shift lewat tengah malam
                }
                $worked = (int) $in->diffInMinutes($out) - $schedule->breakOverlapMinutes($date, $tz, $in, $out);
                $row->fill([
                    'check_out_at' => $out->utc(),
                    'worked_minutes' => max(0, $worked),
                    'is_early_leave' => $schedule->isFlexible()
                        ? $worked < ($schedule->minDailyMinutes ?? 0)
                        : (! $schedule->isDayOff && $out->lt($schedule->endAt($date, $tz))),
                ]);
                if ($row->status === AttendanceStatus::Incomplete) {
                    [$status] = $this->attendance->resolveStatus($schedule, $date, $tz, $in);
                    $row->status = $status;
                }
            }
            $row->fill([
                'schedule_snapshot' => $schedule->toArray(),
                'note' => 'Dikoreksi: '.str($c->reason)->limit(150),
            ])->save();

            $c->update(['status' => LeaveStatus::Approved, 'approved_by' => $approver->id, 'approved_at' => now(), 'review_note' => $note]);

            return $row;
        });

        $this->notify($c, true);

        return $row;
    }

    public function reject(AttendanceCorrection $c, User $approver, string $note): void
    {
        $c->update(['status' => LeaveStatus::Rejected, 'approved_by' => $approver->id, 'approved_at' => now(), 'review_note' => $note]);
        $this->notify($c, false);
    }

    private function notify(AttendanceCorrection $c, bool $approved): void
    {
        $this->push->send($c->employee, 'Koreksi absen '.($approved ? 'disetujui' : 'ditolak'),
            'Koreksi tanggal '.$c->date->translatedFormat('d M Y').($approved ? ' sudah diterapkan.' : ': '.$c->review_note),
            ['type' => 'correction', 'id' => (string) $c->id]);
    }
}
