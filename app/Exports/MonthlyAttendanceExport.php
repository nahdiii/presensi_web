<?php

namespace App\Exports;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Rekap bulanan untuk tunjangan kehadiran/remunerasi: satu lembar ringkasan
 * per pegawai dan satu lembar rincian harian.
 */
class MonthlyAttendanceExport implements WithMultipleSheets
{
    /** @param array<int>|null $unitIds null = semua unit */
    public function __construct(private readonly string $month, private readonly ?array $unitIds = null) {}

    public function sheets(): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();
        $end = $start->endOfMonth();

        $employees = Employee::with(['user', 'unit'])
            ->when($this->unitIds !== null, fn ($q) => $q->whereIn('unit_id', $this->unitIds))
            ->get()
            ->sortBy(fn (Employee $e) => [$e->unit?->name, $e->user->name])
            ->values();

        $attendances = Attendance::with(['checkInOffice', 'checkOutOffice'])
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->orderBy('date')
            ->get()
            ->groupBy('employee_id');

        return [
            new ArraySheet('Rekap '.$this->month, $this->recapHeadings(), $this->recapRows($employees, $attendances)),
            new ArraySheet('Rincian harian', $this->detailHeadings(), $this->detailRows($employees, $attendances)),
        ];
    }

    private function recapHeadings(): array
    {
        return ['NIP', 'NIDN', 'Nama', 'Jenis', 'Unit', 'Hari tercatat', 'Tepat waktu', 'Terlambat', 'Total menit terlambat',
            'Tidak absen pulang', 'Alpa', 'Izin/Cuti', 'Sakit', 'Tugas luar', 'Pulang awal', 'Total jam hadir', 'Rata-rata jam/hari hadir'];
    }

    /** @param Collection<int, Collection<int, Attendance>> $byEmployee */
    private function recapRows(Collection $employees, Collection $byEmployee): array
    {
        return $employees->map(function (Employee $e) use ($byEmployee) {
            $rows = $byEmployee->get($e->id, collect());
            $count = fn (AttendanceStatus $s) => $rows->where('status', $s)->count();
            $attended = $rows->whereNotNull('check_in_at')->count();
            $worked = (int) $rows->sum('worked_minutes');

            return [
                $e->nip, $e->nidn, $e->user->name, $e->employee_type->getLabel(), $e->unit?->name,
                $rows->where('status', '!=', AttendanceStatus::Holiday)->count(),
                $count(AttendanceStatus::Present), $count(AttendanceStatus::Late), (int) $rows->sum('late_minutes'),
                $count(AttendanceStatus::Incomplete), $count(AttendanceStatus::Absent), $count(AttendanceStatus::Leave),
                $count(AttendanceStatus::Sick), $count(AttendanceStatus::Duty), $rows->where('is_early_leave', true)->count(),
                round($worked / 60, 2), $attended ? round($worked / 60 / $attended, 2) : 0,
            ];
        })->all();
    }

    private function detailHeadings(): array
    {
        return ['Tanggal', 'NIP', 'Nama', 'Unit', 'Status', 'Jadwal', 'Masuk', 'Lokasi masuk', 'Jarak masuk (m)',
            'Pulang', 'Lokasi pulang', 'Terlambat (menit)', 'Hadir (menit)', 'Pulang awal', 'Catatan'];
    }

    private function detailRows(Collection $employees, Collection $byEmployee): array
    {
        $rows = [];
        foreach ($employees as $e) {
            foreach ($byEmployee->get($e->id, collect()) as $a) {
                /** @var Attendance $a */
                $tz = $a->checkInOffice?->timezone ?? config('attendance.timezone');
                $s = $a->schedule_snapshot ?? [];
                $rows[] = [
                    $a->date->toDateString(), $e->nip, $e->user->name, $e->unit?->name, $a->status->getLabel(),
                    ($s['is_day_off'] ?? false) ? 'Libur' : (($s['start_time'] ?? '-').'–'.($s['end_time'] ?? '-')),
                    $a->check_in_at?->setTimezone($tz)->format('H:i'), $a->checkInOffice?->name, $a->check_in_distance_m,
                    $a->check_out_at?->setTimezone($a->checkOutOffice?->timezone ?? $tz)->format('H:i'), $a->checkOutOffice?->name,
                    $a->late_minutes, $a->worked_minutes, $a->is_early_leave ? 'Ya' : '', $a->note,
                ];
            }
        }

        return $rows;
    }
}
