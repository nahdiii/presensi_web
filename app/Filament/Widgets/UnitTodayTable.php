<?php

namespace App\Filament\Widgets;

use App\Models\Unit;
use App\Services\TodayOverview;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Rekap hari ini per unit: hadir, terlambat, belum absen. */
class UnitTodayTable extends TableWidget
{
    protected static ?int $sort = 4;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Per unit hari ini';

    private ?array $counts = null;

    private function counts(): array
    {
        return $this->counts ??= app(TodayOverview::class)->rows(auth()->user()->supervisedUnitIds())
            ->groupBy(fn ($r) => $r['employee']->unit_id)
            ->map(fn ($rows) => $rows->countBy('state')->all() + ['scheduled' => $rows->where('scheduled', true)->count()])
            ->all();
    }

    public function table(Table $table): Table
    {
        $units = auth()->user()->supervisedUnitIds();
        $col = fn (string $key, string $label, ?string $color = null) => TextColumn::make($key)->label($label)
            ->state(fn (Unit $u) => $this->counts()[$u->id][$key] ?? 0)->color($color)->alignCenter();

        return $table
            ->query(fn (): Builder => Unit::query()->has('employees')->when($units !== null, fn ($query) => $query->whereIn('id', $units)))
            ->defaultSort('name')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Unit')->weight('semibold')
                    ->description(fn (Unit $u) => $u->type?->getLabel()),
                $col('scheduled', 'Terjadwal'),
                $col('present', 'Tepat waktu', 'success'),
                $col('late', 'Terlambat', 'warning'),
                $col('leave', 'Izin', 'info'),
                $col('not_yet', 'Belum absen', 'danger'),
                TextColumn::make('rate')->label('Kehadiran')->alignEnd()->badge()
                    ->state(function (Unit $u) {
                        $c = $this->counts()[$u->id] ?? [];

                        return ($c['scheduled'] ?? 0)
                            ? round((($c['present'] ?? 0) + ($c['late'] ?? 0)) / $c['scheduled'] * 100).'%'
                            : '-';
                    })
                    ->color(fn (string $state) => match (true) {
                        $state === '-' => 'gray',
                        (int) $state >= 90 => 'success',
                        (int) $state >= 70 => 'warning',
                        default => 'danger',
                    }),
            ]);
    }
}
