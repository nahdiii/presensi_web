<?php

namespace App\Filament\Resources\Courses;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\Courses\Pages\ManageCourses;
use App\Models\Course;
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

class CourseResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Course::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $modelLabel = 'mata kuliah';

    protected static ?string $pluralModelLabel = 'Mata Kuliah';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Perkuliahan';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->label('Kode')->required()->maxLength(30)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('institution_id', auth()->user()->institution_id)),
            TextInput::make('credits')->label('SKS')->numeric()->minValue(1)->maxValue(24)->default(2)->required(),
            TextInput::make('name')->label('Nama mata kuliah')->required()->maxLength(150)->columnSpanFull(),
            Select::make('unit_id')->label('Prodi pengampu')->relationship('unit', 'name')->searchable()->preload(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('unit')->withCount('schedules'))
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                TextColumn::make('name')->label('Mata kuliah')->searchable()->weight('semibold'),
                TextColumn::make('credits')->label('SKS'),
                TextColumn::make('unit.name')->label('Prodi')->placeholder('-'),
                TextColumn::make('schedules_count')->label('Jadwal'),
            ])
            ->filters([
                SelectFilter::make('unit_id')->label('Prodi')->options(fn () => Unit::orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCourses::route('/')];
    }
}
