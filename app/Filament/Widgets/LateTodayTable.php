<?php

namespace App\Filament\Widgets;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LateTodayTable extends TableWidget
{
    protected static ?int $sort = 5;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Terlambat hari ini';

    public function table(Table $table): Table
    {
        $units = auth()->user()->supervisedUnitIds();
        $tz = config('attendance.timezone');

        return $table
            ->query(fn (): Builder => Attendance::query()
                ->with(['employee.user', 'employee.unit', 'checkInOffice'])
                ->whereDate('date', CarbonImmutable::now($tz)->toDateString())
                ->where('status', AttendanceStatus::Late)
                ->when($units !== null, fn ($query) => $query->whereHas('employee', fn ($e) => $e->whereIn('unit_id', $units))))
            ->defaultSort('late_minutes', 'desc')
            ->emptyStateHeading('Belum ada yang terlambat hari ini')
            ->emptyStateIcon('heroicon-o-face-smile')
            ->columns([
                TextColumn::make('employee.user.name')->label('Pegawai')->weight('semibold')
                    ->description(fn (Attendance $r) => $r->employee->nip),
                TextColumn::make('employee.unit.name')->label('Unit'),
                TextColumn::make('check_in_at')->label('Masuk')->dateTime('H:i', $tz),
                TextColumn::make('late_minutes')->label('Terlambat')->suffix(' menit')->badge()->color('warning'),
                TextColumn::make('checkInOffice.name')->label('Lokasi'),
            ]);
    }
}
