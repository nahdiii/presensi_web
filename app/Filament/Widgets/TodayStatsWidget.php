<?php

namespace App\Filament\Widgets;

use App\Services\TodayOverview;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TodayStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    protected function getHeading(): ?string
    {
        return 'Kehadiran hari ini';
    }

    protected function getColumns(): int|array|null
    {
        return ['sm' => 2, 'lg' => 3, 'xl' => 5];
    }

    protected function getStats(): array
    {
        $rows = app(TodayOverview::class)->rows(auth()->user()->supervisedUnitIds());
        $count = fn (string $state) => $rows->where('state', $state)->count();
        $scheduled = $rows->where('scheduled', true)->count();
        $pct = fn (int $n) => ($scheduled ? round($n / $scheduled * 100) : 0).'% dari terjadwal';

        $stat = fn (string $label, int $value, string $color, Heroicon $icon) => Stat::make($label, $value)
            ->icon($icon)->color($color)->extraAttributes(['class' => "pk-stat-$color"]);

        return [
            $stat('Terjadwal', $scheduled, 'primary', Heroicon::OutlinedUsers)->description('wajib hadir hari ini'),
            $stat('Tepat waktu', $count('present'), 'success', Heroicon::OutlinedCheckCircle)->description($pct($count('present'))),
            $stat('Terlambat', $count('late'), 'warning', Heroicon::OutlinedClock)->description($pct($count('late'))),
            $stat('Izin, sakit, tugas', $count('leave'), 'info', Heroicon::OutlinedDocumentCheck)->description($pct($count('leave'))),
            $stat('Belum absen', $count('not_yet'), 'danger', Heroicon::OutlinedExclamationTriangle)->description($pct($count('not_yet'))),
        ];
    }
}
