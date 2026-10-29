<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Menghitung ulang presensi pada rentang tanggal dengan jadwal dan aturan
 * potongan yang berlaku untuk tanggal-tanggal itu. Dijalankan setelah jam
 * kerja atau aturan potongan diubah dengan tanggal berlaku ke belakang,
 * atau lewat tombol "Hitung ulang" di panel admin — sehingga menit
 * terlambat, pulang cepat, dan potongan tidak perlu diperbaiki satu per satu.
 */
class RecalculationService
{
    public function __construct(
        private readonly ShiftResolver $shifts,
        private readonly AttendanceService $attendance,
    ) {}

    /** @return int jumlah baris presensi yang berubah */
    public function run(int $institutionId, string $from, string $until, ?array $employeeIds = null): int
    {
        $changed = 0;
        Attendance::with('employee.offices', 'checkInOffice')
            ->where('institution_id', $institutionId)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $until)
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->chunkById(200, function ($rows) use (&$changed) {
                foreach ($rows as $row) {
                    $changed += (int) $this->recalculate($row);
                }
            });

        // Hari yang sebelumnya libur tetapi kini hari kerja: tandai alpa/izin
        // seperti tutup hari biasa (yang sudah ada barisnya dilewati).
        foreach (CarbonPeriod::create($from, $until) as $day) {
            Artisan::call('attendance:close-day', ['date' => $day->toDateString()]);
        }

        return $changed;
    }

    public function recalculate(Attendance $row): bool
    {
        $employee = $row->employee;
        $date = $row->date->toDateString();
        $tz = $row->checkInOffice?->timezone ?? $employee->offices->first()?->timezone ?? config('attendance.timezone');

        return DB::transaction(function () use ($row, $employee, $date, $tz) {
            $schedule = $this->shifts->resolve($employee, $date);
            if ($row->check_in_at) {
                $row->schedule_snapshot = $schedule->toArray();
                $this->attendance->applyTimes($row, $schedule, $tz);
            } elseif ($row->status === AttendanceStatus::Absent) {
                if ($schedule->isDayOff) {
                    // Menurut jadwal baru bukan hari kerja, jadi bukan alpa.
                    $row->delete();

                    return true;
                }
                $row->schedule_snapshot = $schedule->toArray();
            }
            // Izin, sakit, tugas luar, dan libur tidak diubah; potongannya
            // tetap dihitung ulang karena aturan potongan bisa berganti.
            app(DeductionService::class)->apply($row);
            if (! $row->isDirty()) {
                return false;
            }
            $row->save();

            return true;
        });
    }
}
