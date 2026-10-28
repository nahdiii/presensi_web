<?php

namespace App\Console\Commands;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Enums\LeaveStatus;
use App\Models\Attendance;
use App\Models\CalendarEvent;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\ShiftResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Menutup hari presensi: alpa bagi yang terjadwal tetapi tidak absen,
 * incomplete bagi yang tidak absen pulang, dan izin/libur bila berlaku.
 * Aman dijalankan berulang. Setiap malam memproses hari ini dan kemarin,
 * supaya shift yang melewati tengah malam ikut tertutup keesokan harinya.
 */
#[Signature('attendance:close-day {date? : Tanggal (Y-m-d); default hari ini dan kemarin}')]
#[Description('Tandai alpa, tidak absen pulang, izin, dan libur untuk hari yang sudah lewat')]
class CloseAttendanceDay extends Command
{
    public function handle(ShiftResolver $shifts): int
    {
        $tz = config('attendance.timezone');
        $now = CarbonImmutable::now($tz);
        $dates = $this->argument('date')
            ? [$this->argument('date')]
            : [$now->subDay()->toDateString(), $now->toDateString()];

        $counts = [];
        Employee::with('user', 'offices')
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->where('attendance_type', '!=', AttendanceType::Session)
            ->chunkById(200, function ($employees) use ($dates, $shifts, $now, $tz, &$counts) {
                foreach ($employees as $employee) {
                    foreach ($dates as $date) {
                        $result = $this->closeFor($employee, $date, $shifts, $now, $tz);
                        if ($result) {
                            $counts[$result] = ($counts[$result] ?? 0) + 1;
                        }
                    }
                }
            });

        foreach ($counts as $status => $n) {
            $this->line("$status: $n");
        }
        $this->info('Selesai untuk '.implode(', ', $dates).'.');

        return self::SUCCESS;
    }

    private function closeFor(Employee $employee, string $date, ShiftResolver $shifts, CarbonImmutable $now, string $tz): ?string
    {
        $tz = $employee->offices->first()?->timezone ?? $tz;
        if ($date < $employee->created_at->setTimezone($tz)->toDateString()) {
            return null; // Belum menjadi pegawai di sistem pada tanggal itu.
        }
        $schedule = $shifts->resolve($employee, $date);
        if (! $schedule->isDayOff) {
            $deadline = $schedule->endAt($date, $tz)->addMinutes(config('attendance.checkout_grace_min'));
            if ($now->lt($deadline)) {
                return null; // Hari kerja ini belum selesai.
            }
        }

        $attendance = Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->first();
        if ($attendance) {
            if ($attendance->check_in_at && ! $attendance->check_out_at && $attendance->status !== AttendanceStatus::Incomplete) {
                $attendance->update(['status' => AttendanceStatus::Incomplete]);

                return 'incomplete';
            }

            return null;
        }

        $leave = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', LeaveStatus::Approved)
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)
            ->first();

        $status = match (true) {
            $leave !== null && ! $schedule->isDayOff => $leave->type->attendanceStatus(),
            $schedule->isDayOff && CalendarEvent::where('institution_id', $employee->institution_id)->whereDate('date', $date)->exists() => AttendanceStatus::Holiday,
            $schedule->isDayOff => null, // Akhir pekan biasa tidak perlu dicatat.
            default => AttendanceStatus::Absent,
        };
        if (! $status) {
            return null;
        }

        Attendance::create([
            'institution_id' => $employee->institution_id,
            'employee_id' => $employee->id,
            'date' => $date,
            'status' => $status,
            'schedule_snapshot' => $schedule->toArray(),
            'note' => $schedule->isDayOff ? $schedule->reason : null,
        ]);

        return $status->value;
    }
}
