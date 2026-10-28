<?php

namespace App\Filament\Resources\AttendanceAttempts;

use App\Enums\RejectReason;
use App\Filament\Concerns\ScopedToSupervisedUnits;
use App\Filament\Resources\AttendanceAttempts\Pages\ListAttendanceAttempts;
use App\Models\AttendanceAttempt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Jejak audit: setiap percobaan absen, termasuk yang ditolak. */
class AttendanceAttemptResource extends Resource
{
    use ScopedToSupervisedUnits;

    protected static ?string $model = AttendanceAttempt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $modelLabel = 'percobaan absen';

    protected static ?string $pluralModelLabel = 'Log Percobaan Absen';

    protected static ?int $navigationSort = 9;

    public static function getNavigationGroup(): ?string
    {
        return 'Presensi';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.user', 'office']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s', config('attendance.timezone'))->sortable(),
                TextColumn::make('employee.user.name')->label('Pegawai')->searchable(),
                TextColumn::make('type')->label('Jenis')->formatStateUsing(fn ($s) => ['in' => 'Masuk', 'out' => 'Pulang', 'check' => 'Cek acak'][$s] ?? $s),
                TextColumn::make('result')->label('Hasil')->badge()
                    ->formatStateUsing(fn ($s) => $s === 'accepted' ? 'Diterima' : 'Ditolak')
                    ->color(fn ($s) => $s === 'accepted' ? 'success' : 'danger'),
                TextColumn::make('reason')->label('Alasan')->badge()->color('gray')->placeholder('-'),
                TextColumn::make('office.name')->label('Lokasi terdekat')->placeholder('-'),
                TextColumn::make('distance_m')->label('Jarak')->suffix(' m')->numeric(0)->placeholder('-'),
                TextColumn::make('accuracy_m')->label('Akurasi')->prefix('±')->suffix(' m')->numeric(0),
                IconColumn::make('is_mocked')->label('Fake GPS')->boolean()->trueColor('danger'),
                TextColumn::make('device_uid')->label('Perangkat')->limit(10)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('result')->label('Hasil')->options(['accepted' => 'Diterima', 'rejected' => 'Ditolak']),
                SelectFilter::make('reason')->label('Alasan')->options(RejectReason::class)->multiple(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAttendanceAttempts::route('/')];
    }
}
