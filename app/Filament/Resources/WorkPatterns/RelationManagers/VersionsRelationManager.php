<?php

namespace App\Filament\Resources\WorkPatterns\RelationManagers;

use App\Models\WorkPatternVersion;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Riwayat jam kerja: kapan diubah, oleh siapa, dan mulai berlaku kapan. Hanya-baca. */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Riwayat jam kerja';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $today = now(config('attendance.timezone'))->toDateString();
        $current = $this->getOwnerRecord()->versionFor($today)->id;

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('creator')->reorder()->orderByDesc('effective_from')->orderByDesc('id'))
            ->paginated([10, 25])
            ->columns([
                TextColumn::make('effective_from')->label('Berlaku mulai')->date('d M Y')
                    ->badge()->color(fn (WorkPatternVersion $r) => match (true) {
                        $r->id === $current => 'success',
                        $r->effective_from->toDateString() > $today => 'info',
                        default => 'gray',
                    })
                    ->description(fn (WorkPatternVersion $r) => match (true) {
                        $r->id === $current => 'Berlaku sekarang',
                        $r->effective_from->toDateString() > $today => 'Akan berlaku',
                        default => null,
                    }),
                TextColumn::make('summary')->label('Jam kerja')->state(fn (WorkPatternVersion $r) => $r->summary())->wrap(),
                TextColumn::make('late_tolerance_min')->label('Toleransi')->suffix(' mnt'),
                TextColumn::make('note')->label('Alasan')->placeholder('-')->wrap(),
                TextColumn::make('creator.name')->label('Diubah oleh')->placeholder('-')
                    ->description(fn (WorkPatternVersion $r) => $r->created_at?->setTimezone(config('attendance.timezone'))->translatedFormat('d M Y H:i')),
            ]);
    }
}
