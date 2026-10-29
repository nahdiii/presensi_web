<?php

namespace App\Filament\Resources\TeachingSessions;

use App\Enums\AttendanceStatus;
use App\Filament\Concerns\ScopedToSupervisedUnits;
use App\Filament\Resources\TeachingSessions\Pages\ListTeachingSessions;
use App\Filament\Resources\TeachingSessions\Pages\ViewTeachingSession;
use App\Models\TeachingSession;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/** Presensi per sesi mengajar; sesi tanpa absen ditandai alpa oleh close-day. */
class TeachingSessionResource extends Resource
{
    use ScopedToSupervisedUnits;

    protected static ?string $model = TeachingSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $modelLabel = 'sesi mengajar';

    protected static ?string $pluralModelLabel = 'Sesi Mengajar';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Perkuliahan';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    private static function time(TeachingSession $r): ?string
    {
        return $r->checked_in_at?->copy()->setTimezone($r->office?->timezone ?? config('attendance.timezone'))->format('H:i');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.user', 'employee.unit', 'office']))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('date')->orderByDesc('start_time'))
            ->columns([
                TextColumn::make('date')->label('Tanggal')->date('D, d M Y')->sortable()
                    ->description(fn (TeachingSession $r) => substr($r->start_time, 0, 5).'–'.substr($r->end_time, 0, 5)),
                TextColumn::make('employee.user.name')->label('Dosen')->searchable()->weight('semibold')
                    ->description(fn (TeachingSession $r) => $r->employee->unit?->name),
                TextColumn::make('course_name')->label('Mata kuliah')->searchable()
                    ->description(fn (TeachingSession $r) => "{$r->course_code} · Kelas {$r->class_name}".($r->room ? " · {$r->room}" : '')),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('checked_in_at')->label('Absen')->formatStateUsing(fn (TeachingSession $r) => self::time($r))
                    ->description(fn (TeachingSession $r) => $r->office?->name)->placeholder('-'),
                TextColumn::make('late_minutes')->label('Telat')->suffix(' mnt')->sortable()
                    ->color(fn ($state) => $state > 0 ? 'warning' : null),
                TextColumn::make('topic')->label('Pokok bahasan')->limit(40)->placeholder('-')->toggleable(),
                TextColumn::make('distance_m')->label('Jarak')->suffix(' m')->numeric(0)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('date')->schema([
                    DatePicker::make('from')->label('Dari')->native(false),
                    DatePicker::make('until')->label('Sampai')->native(false),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($query, $d) => $query->whereDate('date', '>=', $d))
                    ->when($data['until'] ?? null, fn ($query, $d) => $query->whereDate('date', '<=', $d))),
                SelectFilter::make('status')->options(collect(AttendanceStatus::cases())
                    ->reject(fn ($s) => $s === AttendanceStatus::Incomplete)
                    ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])->all())->multiple(),
                SelectFilter::make('unit')->label('Unit')->searchable()
                    ->options(fn () => Unit::orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn ($query, $unit) => $query->whereHas('employee', fn ($e) => $e->where('unit_id', $unit)))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('Sesi')->columns(2)->components([
                TextEntry::make('employee.user.name')->label('Dosen'),
                TextEntry::make('date')->label('Tanggal')->date('l, d M Y'),
                TextEntry::make('course_name')->label('Mata kuliah')
                    ->formatStateUsing(fn (TeachingSession $r) => "{$r->course_code} · {$r->course_name} (Kelas {$r->class_name})"),
                TextEntry::make('start_time')->label('Jadwal')
                    ->formatStateUsing(fn (TeachingSession $r) => substr($r->start_time, 0, 5).'–'.substr($r->end_time, 0, 5).($r->room ? ", {$r->room}" : '')),
                TextEntry::make('status')->badge(),
                TextEntry::make('late_minutes')->label('Terlambat')->suffix(' menit'),
                TextEntry::make('topic')->label('Pokok bahasan')->placeholder('-')->columnSpanFull(),
            ]),
            Section::make('Bukti absen')->components([
                TextEntry::make('checked_in_at')->label('Jam')->formatStateUsing(fn (TeachingSession $r) => self::time($r))->placeholder('-'),
                TextEntry::make('office.name')->label('Lokasi')->placeholder('-'),
                TextEntry::make('distance_m')->label('Jarak / akurasi')
                    ->state(fn (TeachingSession $r) => $r->checked_in_at ? round($r->distance_m).' m / ±'.round($r->accuracy_m).' m' : null)->placeholder('-'),
                TextEntry::make('lat')->label('Koordinat')
                    ->state(fn (TeachingSession $r) => $r->lat ? "{$r->lat}, {$r->lng}" : null)
                    ->url(fn (TeachingSession $r) => $r->lat ? "https://www.google.com/maps?q={$r->lat},{$r->lng}" : null, true)
                    ->placeholder('-'),
                ImageEntry::make('photo_path')->hiddenLabel()->imageHeight(260)
                    ->state(fn (TeachingSession $r) => $r->photo_path
                        ? Storage::disk(config('attendance.photo_disk'))->temporaryUrl($r->photo_path, now()->addMinutes(config('attendance.photo_url_ttl')))
                        : null),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeachingSessions::route('/'),
            'view' => ViewTeachingSession::route('/{record}'),
        ];
    }
}
