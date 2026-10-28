<?php

namespace App\Filament\Resources\Units;

use App\Enums\UnitType;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\Units\Pages\ManageUnits;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UnitResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Unit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $modelLabel = 'unit';

    protected static ?string $pluralModelLabel = 'Unit';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Kepegawaian';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nama unit')->required()->maxLength(150),
            Select::make('type')->label('Jenis')->options(UnitType::class)->required(),
            Select::make('parent_id')->label('Unit induk')->relationship('parent', 'name')->searchable()->preload()
                ->helperText('Mis. prodi berinduk ke fakultas.'),
            Select::make('head_employee_id')->label('Kepala unit')->searchable()
                ->relationship('head', 'id', fn ($query) => $query->with('user'))
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->user->name)
                ->helperText('Menyetujui izin pegawai di unit ini.'),
            Select::make('work_pattern_id')->label('Pola jadwal unit')->relationship('workPattern', 'name')->preload()
                ->helperText('Kosongkan untuk mengikuti unit induk atau pola default institusi.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('type')->label('Jenis')->badge(),
                TextColumn::make('parent.name')->label('Induk')->placeholder('-'),
                TextColumn::make('head.user.name')->label('Kepala unit')->placeholder('-'),
                TextColumn::make('workPattern.name')->label('Pola jadwal')->placeholder('ikut induk/default'),
                TextColumn::make('employees_count')->label('Pegawai')->counts('employees'),
            ])
            ->filters([SelectFilter::make('type')->label('Jenis')->options(UnitType::class)])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUnits::route('/')];
    }
}
