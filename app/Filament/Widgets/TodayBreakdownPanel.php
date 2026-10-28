<?php

namespace App\Filament\Widgets;

use App\Services\TodayOverview;
use Filament\Widgets\Widget;

/** Komposisi pegawai terjadwal hari ini, dalam bar progres. */
class TodayBreakdownPanel extends Widget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.bar-panel';

    protected function getViewData(): array
    {
        $rows = app(TodayOverview::class)->rows(auth()->user()->supervisedUnitIds());
        $total = max(1, $rows->where('scheduled', true)->count());
        $count = fn (string $state) => $rows->where('state', $state)->count();

        return [
            'heading' => 'Status kehadiran hari ini',
            'description' => 'Komposisi pegawai yang terjadwal bekerja',
            'items' => collect([
                ['Tepat waktu', $count('present'), 'hijau'],
                ['Terlambat', $count('late'), 'kuning'],
                ['Izin, sakit & tugas luar', $count('leave'), 'biru'],
                ['Belum absen', $count('not_yet'), 'merah'],
            ])->map(fn ($r) => ['label' => $r[0], 'value' => $r[1], 'percent' => $r[1] / $total * 100, 'color' => $r[2]])->all(),
            'thin' => false,
        ];
    }
}
