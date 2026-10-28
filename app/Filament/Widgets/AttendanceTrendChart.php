<?php

namespace App\Filament\Widgets;

use App\Enums\AttendanceStatus;
use App\Services\AttendanceStats;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

class AttendanceTrendChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 2];

    protected ?string $heading = 'Tren kehadiran';

    protected ?string $description = 'Komposisi status presensi 10 hari kerja terakhir';

    protected ?string $maxHeight = '300px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $days = app(AttendanceStats::class)->recentWorkdays(10, auth()->user()->supervisedUnitIds());
        $series = [
            ['Tepat waktu', [AttendanceStatus::Present], '#10b981'],
            ['Terlambat', [AttendanceStatus::Late], '#f59e0b'],
            ['Tidak absen pulang', [AttendanceStatus::Incomplete], '#fb923c'],
            ['Izin/sakit/tugas', [AttendanceStatus::Leave, AttendanceStatus::Sick, AttendanceStatus::Duty], '#0ea5e9'],
            ['Alpa', [AttendanceStatus::Absent], '#f43f5e'],
        ];

        return [
            'labels' => array_map(fn ($d) => CarbonImmutable::parse($d)->translatedFormat('D d/m'), array_keys($days)),
            'datasets' => array_map(fn ($s) => [
                'label' => $s[0],
                'data' => array_values(array_map(fn ($day) => array_sum(array_map(fn ($st) => $day[$st->value] ?? 0, $s[1])), $days)),
                'backgroundColor' => $s[2],
                'borderRadius' => 3,
                'maxBarThickness' => 22,
                'borderSkipped' => false,
            ], $series),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'scales' => [
                'x' => ['stacked' => true, 'grid' => ['display' => false], 'border' => ['display' => false], 'ticks' => ['color' => '#94a3b8']],
                'y' => ['stacked' => true, 'ticks' => ['precision' => 0, 'color' => '#94a3b8'], 'border' => ['display' => false], 'grid' => ['color' => 'rgba(148,163,184,.15)']],
            ],
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true, 'pointStyle' => 'circle', 'boxWidth' => 6, 'boxHeight' => 6, 'padding' => 16, 'color' => '#64748b']]],
        ];
    }
}
