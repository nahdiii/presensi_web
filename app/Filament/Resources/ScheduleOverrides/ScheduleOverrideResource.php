<?php

namespace App\Filament\Resources\ScheduleOverrides;

use App\Enums\ScheduleScope;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\ScheduleOverrides\Pages\ManageScheduleOverrides;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleOverride;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ScheduleOverrideResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = ScheduleOverride::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $modelLabel = 'pengecualian jadwal';

    protected static ?string $pluralModelLabel = 'Pengecualian Jadwal';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Pengaturan';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('reason')->label('Keterangan')->placeholder('Jam kerja Ramadan, libur semester, tugas Sabtu…')
                ->required()->columnSpanFull()->maxLength(150),
            Select::make('scope_type')->label('Berlaku untuk')->options(ScheduleScope::class)->required()->live()
                ->afterStateUpdated(fn ($set) => $set('scope_id', null)),
            Select::make('scope_id')->label('Pilih')->searchable()
                ->hidden(fn (Get $get) => in_array($get('scope_type'), [null, ScheduleScope::Institution, ScheduleScope::Institution->value], true))
                ->required()
                ->options(fn (Get $get) => self::scopeOptions($get('scope_type'))),
            DatePicker::make('start_date')->label('Mulai')->required()->native(false),
            DatePicker::make('end_date')->label('Sampai')->required()->native(false)->afterOrEqual('start_date'),
            Toggle::make('is_day_off')->label('Libur')->live()->helperText('Aktifkan untuk meliburkan tanggal ini.'),
            Select::make('work_pattern_id')->label('Pola jadwal pengganti')->relationship('workPattern', 'name')->preload()
                ->hidden(fn (Get $get) => (bool) $get('is_day_off'))->required(fn (Get $get) => ! $get('is_day_off')),
        ]);
    }

    private static function scopeOptions(ScheduleScope|string|null $scope): array
    {
        $scope = is_string($scope) ? ScheduleScope::tryFrom($scope) : $scope;

        return match ($scope) {
            ScheduleScope::Office => Office::pluck('name', 'id')->all(),
            ScheduleScope::Unit => Unit::orderBy('name')->pluck('name', 'id')->all(),
            ScheduleScope::Employee => Employee::with('user')->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->user->name} ({$e->nip})"])->all(),
            default => [],
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('reason')->label('Keterangan')->searchable(),
                TextColumn::make('scope_type')->label('Berlaku untuk')->badge()
                    ->description(fn (ScheduleOverride $r) => $r->scope_id ? (self::scopeOptions($r->scope_type)[$r->scope_id] ?? '#'.$r->scope_id) : null),
                TextColumn::make('start_date')->label('Mulai')->date('d M Y')->sortable(),
                TextColumn::make('end_date')->label('Sampai')->date('d M Y'),
                IconColumn::make('is_day_off')->label('Libur')->boolean(),
                TextColumn::make('workPattern.name')->label('Pola pengganti')->placeholder('-'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageScheduleOverrides::route('/')];
    }
}
