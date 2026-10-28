<?php

namespace App\Filament\Widgets;

use App\Services\TodayOverview;
use Filament\Widgets\Widget;

/** Persentase hadir hari ini per unit, terendah lebih dulu supaya yang perlu perhatian terlihat. */
class UnitAttendancePanel extends Widget
{
    protected static ?int $sort = 4;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.bar-panel';

    protected function getViewData(): array
    {
        $items = app(TodayOverview::class)->rows(auth()->user()->supervisedUnitIds())
            ->filter(fn ($r) => $r['scheduled'])
            ->groupBy(fn ($r) => $r['employee']->unit?->name ?? 'Tanpa unit')
            ->map(function ($rows, $unit) {
                $present = $rows->whereIn('state', ['present', 'late'])->count();

                return ['label' => $unit, 'value' => "$present/{$rows->count()}", 'percent' => $present / $rows->count() * 100, 'color' => null];
            })
            ->sortBy('percent')
            ->take(10)
            ->values()
            ->all();

        return [
            'heading' => 'Kehadiran per unit',
            'description' => 'Hadir dibanding terjadwal hari ini',
            'items' => $items,
            'thin' => true,
        ];
    }
}
