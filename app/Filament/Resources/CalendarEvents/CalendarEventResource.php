<?php

namespace App\Filament\Resources\CalendarEvents;

use App\Enums\CalendarEventType;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\CalendarEvents\Pages\ManageCalendarEvents;
use App\Models\CalendarEvent;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CalendarEventResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = CalendarEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $modelLabel = 'hari libur';

    protected static ?string $pluralModelLabel = 'Kalender Libur';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return 'Pengaturan';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            DatePicker::make('date')->label('Tanggal')->required()->native(false)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('institution_id', auth()->user()->institution_id)),
            Select::make('type')->label('Jenis')->options(CalendarEventType::class)->required(),
            TextInput::make('name')->label('Keterangan')->required()->columnSpanFull()->maxLength(150),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')->label('Tanggal')->date('l, d M Y')->sortable(),
                TextColumn::make('name')->label('Keterangan')->searchable(),
                TextColumn::make('type')->label('Jenis')->badge(),
            ])
            ->filters([SelectFilter::make('type')->label('Jenis')->options(CalendarEventType::class)])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCalendarEvents::route('/')];
    }
}
