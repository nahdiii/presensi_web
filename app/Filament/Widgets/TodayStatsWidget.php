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

    protected function getColumns(): int|array|null
    {
        return ['sm' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $rows = app(TodayOverview::class)->rows(auth()->user()->supervisedUnitIds());
        $count = fn (string $state) => $rows->where('state', $state)->count();
        $scheduled = $rows->where('scheduled', true)->count();
        $present = $count('present') + $count('late');
        $pct = fn (int $n) => $scheduled ? round($n / $scheduled * 100).'%' : '0%';

        $stat = fn (string $label, int $value, string $color, Heroicon $icon) => Stat::make($label, number_format($value, 0, ',', '.'))
            ->icon($icon)->color($color)->extraAttributes(['class' => "pk-stat-$color"]);

        return [
            $stat('Terjadwal hari ini', $scheduled, 'primary', Heroicon::OutlinedUsers)
                ->description($rows->count().' pegawai aktif'),
            $stat('Sudah hadir', $present, 'success', Heroicon::OutlinedCheckBadge)
                ->description($pct($present).' dari terjadwal'),
            $stat('Terlambat', $count('late'), 'warning', Heroicon::OutlinedClock)
                ->description($present ? round($count('late') / $present * 100).'% dari yang hadir' : 'belum ada yang hadir'),
            $stat('Belum absen', $count('not_yet'), 'danger', Heroicon::OutlinedExclamationTriangle)
                ->description($count('leave').' izin, sakit, atau tugas luar'),
        ];
    }
}
