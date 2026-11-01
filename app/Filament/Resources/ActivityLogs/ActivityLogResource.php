<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Riwayat aktivitas pengguna; siapa melihat siapa diatur ActivityLog::scopeVisibleTo. */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'aktivitas';

    protected static ?string $pluralModelLabel = 'Log Aktivitas';

    protected static ?int $navigationSort = 99;

    public static function getNavigationGroup(): ?string
    {
        return 'Pengaturan';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user.roles'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s', config('attendance.timezone'))->sortable(),
                TextColumn::make('user.name')->label('Pengguna')->searchable()
                    ->description(fn (ActivityLog $r) => $r->user?->roleLabel()),
                TextColumn::make('action')->label('Jenis')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => ActivityLog::ACTIONS[$state] ?? $state),
                TextColumn::make('description')->label('Aktivitas')->wrap()->searchable(),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_id')->label('Pengguna')->searchable()
                    ->options(fn () => User::whereIn('id', ActivityLog::visibleTo(auth()->user())->select('user_id'))
                        ->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('action')->label('Jenis')->options(ActivityLog::ACTIONS)->multiple(),
                Filter::make('date')->schema([
                    DatePicker::make('from')->label('Dari')->native(false),
                    DatePicker::make('until')->label('Sampai')->native(false),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($query, $d) => $query->where('created_at', '>=', CarbonImmutable::parse($d, config('attendance.timezone'))->startOfDay()->utc()))
                    ->when($data['until'] ?? null, fn ($query, $d) => $query->where('created_at', '<=', CarbonImmutable::parse($d, config('attendance.timezone'))->endOfDay()->utc()))),
            ])
            ->recordActions([ViewAction::make()->modalHeading('Detail aktivitas')]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s', config('attendance.timezone')),
            TextEntry::make('user.name')->label('Pengguna')->placeholder('-'),
            TextEntry::make('description')->label('Aktivitas')->columnSpanFull(),
            TextEntry::make('details')->label('Perubahan')->columnSpanFull()->listWithLineBreaks()->placeholder('-')
                ->state(fn (ActivityLog $record) => $record->changeLines()),
            TextEntry::make('ip')->label('Alamat IP')->placeholder('-'),
            TextEntry::make('user_agent')->label('Perangkat')->placeholder('-')->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListActivityLogs::route('/')];
    }
}
