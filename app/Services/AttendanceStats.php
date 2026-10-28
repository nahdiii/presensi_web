<?php

namespace App\Services;

use App\Models\Attendance;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/** Angka agregat presensi per hari untuk grafik dashboard. */
class AttendanceStats
{
    /**
     * Jumlah presensi per status untuk setiap tanggal dalam rentang.
     *
     * @param  array<int>|null  $unitIds
     * @return array<string, array<string, int>> [Y-m-d => [status => jumlah]]
     */
    public function daily(CarbonImmutable $from, CarbonImmutable $to, ?array $unitIds = null): array
    {
        $rows = Attendance::query()
            ->selectRaw('date, status, count(*) as total')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->when($unitIds !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->whereIn('unit_id', $unitIds)))
            ->groupBy('date', 'status')
            ->get();

        $days = [];
        foreach (CarbonPeriod::create($from, $to) as $d) {
            $days[$d->toDateString()] = [];
        }
        foreach ($rows as $r) {
            $days[$r->date->toDateString()][$r->status->value] = (int) $r->total;
        }

        return $days;
    }

    /** Hari kerja (ada catatan presensi) terakhir sebanyak $n, termasuk hari ini. */
    public function recentWorkdays(int $n, ?array $unitIds = null): array
    {
        $today = CarbonImmutable::now(config('attendance.timezone'))->startOfDay();
        $daily = $this->daily($today->subDays($n * 2 + 7), $today, $unitIds);
        $today = $today->toDateString();

        return array_slice(array_filter($daily, fn ($counts, $date) => $counts || $date === $today, ARRAY_FILTER_USE_BOTH), -$n, null, true);
    }
}
