<?php

namespace App\Filament\Widgets;

use App\Enums\AttendanceStatus;
use App\Services\AttendanceStats;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** Komposisi status presensi sejak awal bulan. */
class MonthCompositionChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Komposisi bulan ini';

    protected ?string $maxHeight = '300px';

    private const COLORS = [
        'present' => '#10b981', 'late' => '#f59e0b', 'incomplete' => '#fb923c', 'absent' => '#f43f5e',
        'leave' => '#0ea5e9', 'sick' => '#8b5cf6', 'duty' => '#6366f1',
    ];

    public function getDescription(): ?string
    {
        return 'Akumulasi status presensi '.CarbonImmutable::now(config('attendance.timezone'))->translatedFormat('F Y');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $now = CarbonImmutable::now(config('attendance.timezone'));
        $totals = [];
        foreach (app(AttendanceStats::class)->daily($now->startOfMonth(), $now, auth()->user()->supervisedUnitIds()) as $day) {
            foreach ($day as $status => $n) {
                $totals[$status] = ($totals[$status] ?? 0) + $n;
            }
        }
        $statuses = array_values(array_filter(AttendanceStatus::cases(),
            fn ($s) => isset(self::COLORS[$s->value]) && ($totals[$s->value] ?? 0) > 0));

        return [
            'labels' => array_map(fn ($s) => $s->getLabel(), $statuses),
            'datasets' => [[
                'data' => array_map(fn ($s) => $totals[$s->value], $statuses),
                'backgroundColor' => array_map(fn ($s) => self::COLORS[$s->value], $statuses),
                'borderWidth' => 0,
                'hoverOffset' => 6,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'cutout' => '70%',
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => [
                'usePointStyle' => true, 'pointStyle' => 'circle', 'boxWidth' => 6, 'boxHeight' => 6, 'padding' => 14, 'color' => '#64748b',
            ]]],
        ];
    }
}
