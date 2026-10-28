<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Kondisi kehadiran hari ini untuk dashboard: siapa hadir, terlambat,
 * izin, dan belum absen, opsional dibatasi ke unit tertentu.
 */
class TodayOverview
{
    public function __construct(private readonly ShiftResolver $shifts) {}

    /**
     * @param  array<int>|null  $unitIds
     * @return Collection<int, array{employee: Employee, scheduled: bool, attendance: ?Attendance, state: string}>
     */
    public function rows(?array $unitIds = null): Collection
    {
        $today = CarbonImmutable::now(config('attendance.timezone'))->toDateString();
        $employees = Employee::with(['user', 'unit'])
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->when($unitIds !== null, fn ($q) => $q->whereIn('unit_id', $unitIds))
            ->get();
        $attendances = Attendance::whereDate('date', $today)->whereIn('employee_id', $employees->pluck('id'))->get()->keyBy('employee_id');

        return $employees->map(function (Employee $e) use ($attendances, $today) {
            $a = $attendances->get($e->id);
            $scheduled = ! $this->shifts->resolve($e, $today)->isDayOff;
            $state = match (true) {
                $a?->status === AttendanceStatus::Late => 'late',
                $a?->check_in_at !== null => 'present',
                in_array($a?->status, [AttendanceStatus::Leave, AttendanceStatus::Sick, AttendanceStatus::Duty], true) => 'leave',
                $scheduled => 'not_yet',
                default => 'off',
            };

            return ['employee' => $e, 'scheduled' => $scheduled, 'attendance' => $a, 'state' => $state];
        });
    }
}
