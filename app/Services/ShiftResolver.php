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
 *  6. Pola mingguan lokasi kampus pegawai.
 *  7. Pola default institusi.
 *
 * Satu pengecualian: pengecualian grup yang hanya mengganti jam (mis. jam
 * Ramadan) tidak menghapus hari libur di kalender. Libur tetap libur.
 *
 * Jam sebuah pola diambil dari versi yang berlaku pada tanggal itu, jadi
 * perubahan jam dengan tanggal berlaku ke depan tidak memengaruhi hari ini.
 */
class ShiftResolver
{
    public function resolve(Employee $employee, CarbonImmutable|string $date): DaySchedule
    {
        $date = CarbonImmutable::parse($date)->toDateString();
        $employee->loadMissing(['unit', 'workPattern.versions', 'institution.defaultWorkPattern.versions', 'offices.workPattern.versions']);
        $lineage = $employee->unit?->lineage() ?? [];

        $override = $this->override(ScheduleScope::Employee, [$employee->id], $date);
        if ($override) {
            return $this->fromOverride($override, $date, 'override:employee');
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
            return $this->fromOverride($groupOverride, $date, 'override:'.$groupOverride->scope_type->value);
        }
        if ($holiday) {
            return DaySchedule::dayOff('calendar', $holiday->name);
        }

        if ($employee->workPattern) {
            return $this->fromPattern($employee->workPattern, $date, 'employee_pattern');
        }
        foreach ($lineage as $unit) {
            /** @var Unit $unit */
            if ($unit->workPattern) {
                return $this->fromPattern($unit->workPattern->loadMissing('versions'), $date, 'unit_pattern');
            }
        }
        // Pegawai di beberapa kampus: kampus pertama (urut ID) yang punya pola.
        if ($office = $employee->offices->sortBy('id')->first(fn ($o) => $o->workPattern)) {
            return $this->fromPattern($office->workPattern, $date, 'office_pattern');
        }
        if ($pattern = $employee->institution?->defaultWorkPattern) {
            return $this->fromPattern($pattern, $date, 'institution_pattern');
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

        return ScheduleOverride::with('workPattern.versions')
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

    private function fromOverride(ScheduleOverride $o, string $date, string $source): DaySchedule
    {
        if ($o->is_day_off || ! $o->workPattern) {
            return DaySchedule::dayOff($source, $o->reason);
        }

        return $this->fromPattern($o->workPattern, $date, $source, $o->reason);
    }

    private function fromPattern(WorkPattern $pattern, string $date, string $source, ?string $reason = null): DaySchedule
    {
        $version = $pattern->versionFor($date);
        $day = $version->dayFor(CarbonImmutable::parse($date)->isoWeekday());
        if (! $day || $day['is_day_off'] || ! $day['start_time'] || ! $day['end_time']) {
            return DaySchedule::dayOff($source, $reason ?? 'Hari libur', $pattern->name);
        }

        return new DaySchedule(
            source: $source,
            isDayOff: false,
            type: $version->type->value,
            workPatternId: $pattern->id,
            patternName: $pattern->name,
            startTime: $day['start_time'],
            endTime: $day['end_time'],
            breakStart: $day['break_start'] ?? null,
            breakEnd: $day['break_end'] ?? null,
            lateToleranceMin: (int) $version->late_tolerance_min,
            checkinOpenMin: (int) $version->checkin_open_min,
            minDailyMinutes: $version->min_daily_minutes,
            reason: $reason,
            versionId: $version->id,
            effectiveFrom: $version->effective_from?->toDateString(),
        );
    }
}
