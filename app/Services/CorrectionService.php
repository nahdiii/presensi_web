<?php

namespace App\Services;

use App\Enums\LeaveStatus;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
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
        $row = $this->apply($c, $approver, $note, 'Dikoreksi');
        $this->notify($c, true);

        return $row;
    }

    /**
     * Admin SDM mencatat presensi langsung, mis. HP pegawai rusak/hilang.
     * Disimpan sebagai koreksi yang langsung disetujui atas nama admin,
     * sehingga siapa, kapan, dan alasannya tetap tercatat.
     */
    public function recordManual(Employee $employee, string $date, string $checkIn, ?string $checkOut, string $reason, User $admin): Attendance
    {
        $c = $employee->corrections()->create([
            'institution_id' => $employee->institution_id,
            'date' => $date,
            'check_in_time' => $checkIn,
            'check_out_time' => $checkOut,
            'reason' => $reason,
            'status' => LeaveStatus::Pending,
        ]);
        $row = $this->apply($c, $admin, "Dicatat manual oleh {$admin->name}", 'Dicatat manual');
        $this->push->send($employee, 'Presensi dicatat oleh Admin SDM',
            $c->date->translatedFormat('d M Y').' · masuk '.substr($checkIn, 0, 5).($checkOut ? ', pulang '.substr($checkOut, 0, 5) : '').'.',
            ['type' => 'correction', 'id' => (string) $c->id]);

        return $row;
    }

    private function apply(AttendanceCorrection $c, User $approver, ?string $note, string $label): Attendance
    {
        return DB::transaction(function () use ($c, $approver, $note, $label) {
            $employee = $c->employee;
            $date = $c->date->toDateString();
            $tz = $employee->offices->first()?->timezone ?? config('attendance.timezone');
            $row = Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->lockForUpdate()->first()
                ?? new Attendance(['employee_id' => $employee->id, 'date' => $date, 'institution_id' => $employee->institution_id]);
            $schedule = $row->schedule_snapshot
                ? DaySchedule::fromArray($row->schedule_snapshot)
                : $this->shifts->resolve($employee, $date);

            if ($c->check_in_time) {
                $row->check_in_at = CarbonImmutable::parse("$date {$c->check_in_time}", $tz)->utc();
            }
            if ($c->check_out_time && $row->check_in_at) {
                $in = CarbonImmutable::instance($row->check_in_at)->setTimezone($tz);
                $out = CarbonImmutable::parse("$date {$c->check_out_time}", $tz);
                if ($out->lte($in)) {
                    $out = $out->addDay(); // shift lewat tengah malam
                }
                $row->check_out_at = $out->utc();
            }
            $this->attendance->applyTimes($row, $schedule, $tz);
            $row->fill([
                'schedule_snapshot' => $schedule->toArray(),
                'note' => "$label: ".str($c->reason)->limit(150),
            ])->save();

            $c->update(['status' => LeaveStatus::Approved, 'approved_by' => $approver->id, 'approved_at' => now(), 'review_note' => $note]);

            return $row;
        });
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
