<?php

namespace App\Filament\Resources\PresenceChecks;

use App\Filament\Concerns\ScopedToSupervisedUnits;
use App\Filament\Resources\PresenceChecks\Pages\ListPresenceChecks;
use App\Models\PresenceCheck;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Hasil cek kehadiran acak; yang tidak merespons atau di luar radius dilaporkan ke pimpinan. */
class PresenceCheckResource extends Resource
{
    use ScopedToSupervisedUnits;

    protected static ?string $model = PresenceCheck::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $modelLabel = 'cek kehadiran';

    protected static ?string $pluralModelLabel = 'Cek Kehadiran Acak';

    protected static ?int $navigationSort = 4;

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
        $tz = config('attendance.timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.user', 'employee.unit', 'office'])
                ->whereNotIn('result', [PresenceCheck::SCHEDULED, PresenceCheck::CANCELLED]))
            ->defaultSort('sent_at', 'desc')
            ->columns([
                TextColumn::make('sent_at')->label('Dikirim')->dateTime('d M Y, H:i', $tz)->sortable()->placeholder('-'),
                TextColumn::make('employee.user.name')->label('Pegawai')->searchable()->weight('semibold')
                    ->description(fn (PresenceCheck $r) => $r->employee->unit?->name),
                TextColumn::make('result')->label('Hasil')->badge()
                    ->formatStateUsing(fn (string $state) => PresenceCheck::LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        PresenceCheck::OK => 'success',
                        PresenceCheck::PENDING => 'info',
                        PresenceCheck::SKIPPED => 'gray',
                        default => 'danger',
                    }),
                TextColumn::make('responded_at')->label('Dijawab')->dateTime('H:i', $tz)->placeholder('-'),
                TextColumn::make('office.name')->label('Lokasi terdekat')->placeholder('-'),
                TextColumn::make('distance_m')->label('Jarak')->suffix(' m')->numeric(0)->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('result')->label('Hasil')->multiple()->options(collect(PresenceCheck::LABELS)
                    ->except([PresenceCheck::SCHEDULED, PresenceCheck::CANCELLED])->all()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPresenceChecks::route('/')];
    }
}
