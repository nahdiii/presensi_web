<?php

namespace App\Services;

use App\Enums\ScheduleScope;
use App\Models\CalendarEvent;
use App\Models\Employee;
use App\Models\ScheduleOverride;
use App\Models\Unit;
use App\Models\WorkPattern;
use App\Support\DaySchedule;
use Carbon\CarbonImmutable;

/**
 * Mencari aturan jadwal paling spesifik untuk seorang pegawai pada satu
 * tanggal. Urutan prioritas (yang paling atas menang):
 *
 *  1. Pengecualian untuk pegawai itu (mis. dosen menguji hari Sabtu).
 *  2. Pengecualian untuk unit (terdekat dulu), lokasi kampus, lalu institusi.
 *  3. Kalender libur (libur nasional, cuti bersama, libur kampus).
 *  4. Pola mingguan pegawai.
 *  5. Pola mingguan unit, lalu unit induknya.
 *  6. Pola default institusi.
 *
 * Satu pengecualian: pengecualian grup yang hanya mengganti jam (mis. jam
 * Ramadan) tidak menghapus hari libur di kalender. Libur tetap libur.
 */
class ShiftResolver
{
    public function resolve(Employee $employee, CarbonImmutable|string $date): DaySchedule
    {
        $date = CarbonImmutable::parse($date)->toDateString();
        $weekday = CarbonImmutable::parse($date)->isoWeekday();
        $employee->loadMissing(['unit', 'workPattern.days', 'institution.defaultWorkPattern.days', 'offices']);
        $lineage = $employee->unit?->lineage() ?? [];

        $override = $this->override(ScheduleScope::Employee, [$employee->id], $date);
        if ($override) {
            return $this->fromOverride($override, $weekday, 'override:employee');
        }

        $groupOverride = null;
        foreach ($lineage as $unit) {
            if ($groupOverride = $this->override(ScheduleScope::Unit, [$unit->id], $date)) {
                break;
            }
        }
        $groupOverride ??= $this->override(ScheduleScope::Office, $employee->offices->pluck('id')->all(), $date);
        $groupOverride ??= $this->override(ScheduleScope::Institution, [null, $employee->institution_id], $date);

        $holiday = CalendarEvent::where('institution_id', $employee->institution_id)
            ->whereDate('date', $date)->first();

        if ($groupOverride && ($groupOverride->is_day_off || ! $holiday)) {
            return $this->fromOverride($groupOverride, $weekday, 'override:'.$groupOverride->scope_type->value);
        }
        if ($holiday) {
            return DaySchedule::dayOff('calendar', $holiday->name);
        }

        if ($employee->workPattern) {
            return $this->fromPattern($employee->workPattern, $weekday, 'employee_pattern');
        }
        foreach ($lineage as $unit) {
            /** @var Unit $unit */
            if ($unit->workPattern) {
                return $this->fromPattern($unit->workPattern->load('days'), $weekday, 'unit_pattern');
            }
        }
        if ($pattern = $employee->institution?->defaultWorkPattern) {
            return $this->fromPattern($pattern, $weekday, 'institution_pattern');
        }

        return DaySchedule::dayOff('none', 'Belum ada jadwal kerja. Hubungi Bagian SDM.');
    }

    private function override(ScheduleScope $scope, array $ids, string $date): ?ScheduleOverride
    {
        $ids = array_values(array_filter($ids, fn ($id) => $id !== null));
        $includeNull = $scope === ScheduleScope::Institution;
        if (! $ids && ! $includeNull) {
            return null;
        }

        return ScheduleOverride::with('workPattern.days')
            ->where('scope_type', $scope)
            ->where(function ($q) use ($ids, $includeNull) {
                $q->whereIn('scope_id', $ids);
                if ($includeNull) {
                    $q->orWhereNull('scope_id');
                }
            })
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->latest('id')
            ->first();
    }

    private function fromOverride(ScheduleOverride $o, int $weekday, string $source): DaySchedule
    {
        if ($o->is_day_off || ! $o->workPattern) {
            return DaySchedule::dayOff($source, $o->reason);
        }

        return $this->fromPattern($o->workPattern, $weekday, $source, $o->reason);
    }

    private function fromPattern(WorkPattern $pattern, int $weekday, string $source, ?string $reason = null): DaySchedule
    {
        $day = $pattern->dayFor($weekday);
        if (! $day || $day->is_day_off || ! $day->start_time || ! $day->end_time) {
            return DaySchedule::dayOff($source, $reason ?? 'Hari libur', $pattern->name);
        }
        $hm = fn (?string $t) => $t ? substr($t, 0, 5) : null;

        return new DaySchedule(
            source: $source,
            isDayOff: false,
            type: $pattern->type->value,
            workPatternId: $pattern->id,
            patternName: $pattern->name,
            startTime: $hm($day->start_time),
            endTime: $hm($day->end_time),
            breakStart: $hm($day->break_start),
            breakEnd: $hm($day->break_end),
            lateToleranceMin: $pattern->late_tolerance_min,
            checkinOpenMin: $pattern->checkin_open_min,
            minDailyMinutes: $pattern->min_daily_minutes,
            reason: $reason,
        );
    }
}
