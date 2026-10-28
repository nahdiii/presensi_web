<?php

namespace App\Filament\Resources\Offices;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\Offices\Pages\ManageOffices;
use App\Models\Office;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OfficeResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Office::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $modelLabel = 'lokasi kampus';

    protected static ?string $pluralModelLabel = 'Lokasi Kampus';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Pengaturan';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nama')->placeholder('Kampus 1 / Gedung Teknik')->required()->maxLength(120),
            TextInput::make('address')->label('Alamat')->maxLength(255),
            TextInput::make('latitude')->numeric()->required()->minValue(-90)->maxValue(90)
                ->helperText('Klik kanan titik di Google Maps untuk menyalin koordinat.'),
            TextInput::make('longitude')->numeric()->required()->minValue(-180)->maxValue(180),
            TextInput::make('radius_m')->label('Radius (meter)')->numeric()->required()->default(100)->minValue(20)->maxValue(2000),
            Select::make('timezone')->label('Zona waktu')->required()->default('Asia/Makassar')
                ->options(['Asia/Jakarta' => 'WIB (Asia/Jakarta)', 'Asia/Makassar' => 'WITA (Asia/Makassar)', 'Asia/Jayapura' => 'WIT (Asia/Jayapura)']),
            Toggle::make('is_active')->label('Aktif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('address')->label('Alamat')->toggleable(),
                TextColumn::make('latitude')->label('Koordinat')
                    ->formatStateUsing(fn (Office $r) => "{$r->latitude}, {$r->longitude}")
                    ->url(fn (Office $r) => "https://www.google.com/maps?q={$r->latitude},{$r->longitude}", true),
                TextColumn::make('radius_m')->label('Radius')->suffix(' m')->sortable(),
                TextColumn::make('employees_count')->label('Pegawai')->counts('employees'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageOffices::route('/')];
    }
}
