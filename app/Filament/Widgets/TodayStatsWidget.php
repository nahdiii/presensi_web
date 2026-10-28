<?php

namespace App\Filament\Widgets;

use App\Services\TodayOverview;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TodayStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected function getHeading(): ?string
    {
        return 'Kehadiran hari ini';
    }

    protected function getStats(): array
    {
        $rows = app(TodayOverview::class)->rows(auth()->user()->supervisedUnitIds());
        $count = fn (string $state) => $rows->where('state', $state)->count();
        $scheduled = $rows->where('scheduled', true)->count();

        return [
            Stat::make('Terjadwal', $scheduled)->description('pegawai wajib hadir hari ini')->color('gray'),
            Stat::make('Hadir tepat waktu', $count('present'))->color('success')->icon('heroicon-o-check-circle'),
            Stat::make('Terlambat', $count('late'))->color('warning')->icon('heroicon-o-clock'),
            Stat::make('Izin / sakit / tugas', $count('leave'))->color('info')->icon('heroicon-o-document-check'),
            Stat::make('Belum absen', $count('not_yet'))->color('danger')->icon('heroicon-o-exclamation-triangle'),
        ];
    }
}
