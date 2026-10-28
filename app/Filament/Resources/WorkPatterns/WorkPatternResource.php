<?php

namespace App\Filament\Resources\WorkPatterns;

use App\Enums\WorkPatternType;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\WorkPatterns\Pages\CreateWorkPattern;
use App\Filament\Resources\WorkPatterns\Pages\EditWorkPattern;
use App\Filament\Resources\WorkPatterns\Pages\ListWorkPatterns;
use App\Models\WorkPattern;
use App\Models\WorkPatternDay;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkPatternResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = WorkPattern::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $modelLabel = 'pola jadwal';

    protected static ?string $pluralModelLabel = 'Pola Jadwal';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Pengaturan';
    }

    public static function form(Schema $schema): Schema
    {
        $time = fn (string $name, string $label) => TimePicker::make($name)->label($label)->seconds(false)
            ->hidden(fn (Get $get) => (bool) $get('is_day_off'));

        return $schema->components([
            Section::make('Pola')->columns(3)->columnSpanFull()->components([
                TextInput::make('name')->label('Nama')->placeholder('Tendik Reguler')->required()->maxLength(120),
                Select::make('type')->label('Tipe')->options(WorkPatternType::class)->required()->live()
                    ->helperText('Fleksibel: tidak ada terlambat, dinilai dari total jam hadir.'),
                TextInput::make('checkin_open_min')->label('Absen dibuka (menit sebelum jam masuk)')->numeric()->default(60)->required(),
                TextInput::make('late_tolerance_min')->label('Toleransi terlambat (menit)')->numeric()->default(15)
                    ->hidden(fn (Get $get) => in_array($get('type'), [WorkPatternType::Flexible, 'flexible'], true)),
                TextInput::make('min_daily_minutes')->label('Minimal hadir per hari (menit)')->numeric()
                    ->visible(fn (Get $get) => in_array($get('type'), [WorkPatternType::Flexible, 'flexible'], true)),
            ]),
            Section::make('Jam per hari')->columnSpanFull()
                ->description('Jam pulang lebih kecil dari jam masuk berarti shift melewati tengah malam.')
                ->components([
                    Repeater::make('days')->hiddenLabel()->relationship()
                        ->orderColumn('weekday')->reorderable(false)->addable(false)->deletable(false)
                        ->default(collect(WorkPatternDay::WEEKDAYS)->map(fn ($n, $wd) => [
                            'weekday' => $wd, 'is_day_off' => $wd >= 6, 'start_time' => $wd < 6 ? '07:30' : null, 'end_time' => $wd < 6 ? '16:00' : null,
                        ])->values()->all())
                        ->itemLabel(fn (array $state) => WorkPatternDay::WEEKDAYS[$state['weekday'] ?? 0] ?? null)
                        ->columns(5)
                        ->schema([
                            Toggle::make('is_day_off')->label('Libur')->live()->inline(false),
                            $time('start_time', 'Masuk')->required(fn (Get $get) => ! $get('is_day_off')),
                            $time('end_time', 'Pulang')->required(fn (Get $get) => ! $get('is_day_off')),
                            $time('break_start', 'Istirahat mulai'),
                            $time('break_end', 'Istirahat selesai'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('type')->label('Tipe')->badge(),
                TextColumn::make('summary')->label('Jadwal')->state(fn (WorkPattern $r) => $r->days
                    ->reject->is_day_off
                    ->map(fn ($d) => mb_substr(WorkPatternDay::WEEKDAYS[$d->weekday], 0, 3).' '.substr($d->start_time, 0, 5).'–'.substr($d->end_time, 0, 5))
                    ->implode(' · '))->wrap(),
                TextColumn::make('late_tolerance_min')->label('Toleransi')->suffix(' mnt'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('days'))
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkPatterns::route('/'),
            'create' => CreateWorkPattern::route('/create'),
            'edit' => EditWorkPattern::route('/{record}/edit'),
        ];
    }
}
