<?php

namespace App\Filament\Widgets;

use App\Enums\AttendanceStatus;
use App\Services\AttendanceStats;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

/** Akumulasi status presensi sejak awal bulan. */
class MonthRecapPanel extends Widget
{
    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.month-recap';

    protected function getViewData(): array
    {
        $now = CarbonImmutable::now(config('attendance.timezone'));
        $totals = [];
        foreach (app(AttendanceStats::class)->daily($now->startOfMonth(), $now, auth()->user()->supervisedUnitIds()) as $day) {
            foreach ($day as $status => $n) {
                $totals[$status] = ($totals[$status] ?? 0) + $n;
            }
        }
        unset($totals[AttendanceStatus::Holiday->value]);
        $sum = max(1, array_sum($totals));

        return [
            'month' => $now->translatedFormat('F Y'),
            'rows' => collect(AttendanceStatus::cases())
                ->reject(fn ($s) => $s === AttendanceStatus::Holiday)
                ->map(fn ($s) => ['label' => $s->getLabel(), 'value' => $totals[$s->value] ?? 0, 'percent' => round(($totals[$s->value] ?? 0) / $sum * 100)])
                ->values()->all(),
        ];
    }
}
